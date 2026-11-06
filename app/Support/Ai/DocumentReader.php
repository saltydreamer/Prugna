<?php

namespace App\Support\Ai;

use App\Models\AiExtraction;
use App\Models\User;
use App\Support\FatturaPa\FatturaPaReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores an uploaded document and reads it: FatturaPA XML directly, images and PDFs with the AI.
 * Every reading is logged in ai_extractions with model, tokens, cost and time.
 */
class DocumentReader
{
    public const DISK = 'local';

    /**
     * Accepted uploads (photos from phones are JPEG; HEIC must be converted on the phone).
     */
    public const MIMES = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'xml', 'p7m'];

    private const MAX_IMAGE_SIDE = 2000;

    public function __construct(private OpenRouterClient $client, private FatturaPaReader $fatturaPa) {}

    public function read(UploadedFile $file, string $kind, ?User $user, ?string $model = null): AiExtraction
    {
        $contents = (string) $file->get();
        $filename = $file->getClientOriginalName();
        $mimeType = $this->mimeType($file);

        $extraction = new AiExtraction([
            'user_id' => $user?->id,
            'kind' => $kind,
            'original_name' => Str::limit($filename, 250, ''),
            'mime_type' => $mimeType,
            'file_size' => strlen($contents),
            'file_path' => Storage::disk(self::DISK)->putFileAs('documents/'.now()->format('Y/m'), $file, Str::uuid().'.'.$this->extension($file)),
        ]);

        $started = hrtime(true);

        try {
            if ($kind === AiExtraction::KIND_EXPENSE && FatturaPaReader::looks($contents, $filename)) {
                $extraction->fill(['source' => 'xml', 'result' => $this->fatturaPa->read($contents), 'cost' => 0]);
            } else {
                $extraction->fill(['source' => 'ai', ...$this->readWithAi($contents, $mimeType, $filename, $kind, $model)]);
            }

            $extraction->status = 'succeeded';
        } catch (AiException $exception) {
            $extraction->fill(['status' => 'failed', 'error' => $exception->getMessage()]);
        }

        $extraction->duration_ms = (int) ((hrtime(true) - $started) / 1_000_000);
        $extraction->save();

        return $extraction;
    }

    /**
     * @return array<string, mixed>
     */
    private function readWithAi(string $contents, string $mimeType, string $filename, string $kind, ?string $model): array
    {
        if (! in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
            throw new AiException(__("L'AI legge foto (JPG, PNG, WebP) e PDF."));
        }

        if ($model === null && ! $this->client->isConfigured()) {
            throw new AiException(__("La lettura con l'AI non è configurata: attivala in Impostazioni → AI."));
        }

        // A model call can take a minute on long PDFs.
        set_time_limit(180);

        if (str_starts_with($mimeType, 'image/')) {
            [$contents, $mimeType] = self::shrink($contents, $mimeType);
        }

        $definition = $kind === AiExtraction::KIND_ORGANIZATION ? DocumentSchemas::organization() : DocumentSchemas::expense();
        $result = $this->client->extract($definition['instructions'], $definition['name'], $definition['schema'], $contents, $mimeType, $filename, $model);

        return [
            'result' => $result['data'],
            'model' => $result['model'],
            'cost' => $result['cost'],
            'prompt_tokens' => $result['prompt_tokens'],
            'completion_tokens' => $result['completion_tokens'],
        ];
    }

    /**
     * Phone photos are resized to 2000 px: enough to read, much cheaper to send.
     *
     * @return array{0: string, 1: string}
     */
    public static function shrink(string $contents, string $mimeType, int $maxSide = self::MAX_IMAGE_SIDE): array
    {
        if (! function_exists('imagecreatefromstring') || ! ($image = @imagecreatefromstring($contents))) {
            return [$contents, $mimeType];
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxSide / max($width, $height));

        if ($scale === 1 && strlen($contents) < 3_000_000) {
            imagedestroy($image);

            return [$contents, $mimeType];
        }

        $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));
        ob_start();
        imagejpeg($resized ?: $image, null, 85);
        $output = (string) ob_get_clean();
        imagedestroy($image);

        return [$output, 'image/jpeg'];
    }

    private function mimeType(UploadedFile $file): string
    {
        $extension = $this->extension($file);

        return match (true) {
            $extension === 'pdf' => 'application/pdf',
            in_array($extension, ['xml', 'p7m'], true) => 'application/xml',
            default => $file->getMimeType() ?: 'application/octet-stream',
        };
    }

    private function extension(UploadedFile $file): string
    {
        return strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?? 'bin'));
    }
}
