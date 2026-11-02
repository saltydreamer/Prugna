<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationRole;
use App\Enums\Permission;
use App\Models\AiExtraction;
use App\Models\Expense;
use App\Models\Organization;
use App\Support\Ai\DocumentReader;
use App\Support\FatturaPa\FatturaPaReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Carica documento": reads an uploaded file (FatturaPA XML directly, photos and PDFs with the AI)
 * and opens the matching form prefilled. Nothing is saved until the user confirms the form.
 */
class DocumentImportController extends Controller
{
    public function store(Request $request, DocumentReader $reader): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['expense', 'customer', 'supplier'])],
            'file' => ['required', 'file', 'max:20480', 'mimes:'.implode(',', DocumentReader::MIMES)],
        ], [], ['file' => 'documento', 'kind' => 'tipo di documento']);

        $file = $request->file('file');
        $isXml = FatturaPaReader::looks((string) $file->get(), $file->getClientOriginalName());

        if ($data['kind'] === 'expense') {
            $this->authorize('create', Expense::class);
        } else {
            $this->authorize('create', [Organization::class, OrganizationRole::from($data['kind'])]);
        }

        // Electronic invoices are read without AI; everything else needs the permission.
        abort_unless(($isXml && $data['kind'] === 'expense') || $request->user()->can(Permission::AiUse->value), 403);

        $extraction = $reader->read($file, $data['kind'] === 'expense' ? AiExtraction::KIND_EXPENSE : AiExtraction::KIND_ORGANIZATION, $request->user());

        if (! $extraction->succeeded()) {
            throw ValidationException::withMessages(['file' => $extraction->error]);
        }

        return match ($data['kind']) {
            'expense' => to_route('purchases.expenses.create', ['extraction' => $extraction->id]),
            'customer' => to_route('registry.customers.create', ['extraction' => $extraction->id]),
            'supplier' => to_route('registry.suppliers.create', ['extraction' => $extraction->id]),
        };
    }

    /**
     * The original document, shown next to the prefilled form.
     */
    public function file(Request $request, AiExtraction $extraction): StreamedResponse
    {
        abort_unless(
            $extraction->user_id === $request->user()->id || $request->user()->can(Permission::SettingsManage->value),
            403,
        );
        abort_unless($extraction->file_path && Storage::disk(DocumentReader::DISK)->exists($extraction->file_path), 404);

        return Storage::disk(DocumentReader::DISK)->response($extraction->file_path, $extraction->original_name, [], 'inline');
    }
}
