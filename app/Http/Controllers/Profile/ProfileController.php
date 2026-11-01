<?php

namespace App\Http\Controllers\Profile;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdatePreferencesRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('profile/Show', [
            'user' => UserResource::make($user->load('roles'))->resolve(),
            'tab' => $request->query('tab', 'profile'),
            'twoFactor' => [
                'enabled' => $user->two_factor_secret !== null,
                'confirmed' => $user->two_factor_confirmed_at !== null,
                'requiresConfirmation' => Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm'),
            ],
            'sessions' => fn () => app(BrowserSessionController::class)->sessions($request),
        ]);
    }

    public function updatePreferences(UpdatePreferencesRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Preferenze salvate.')]);

        return back();
    }

    /**
     * Permanently delete the authenticated user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if ($user->isSuperAdmin() && User::role(Role::SuperAdmin->value)->count() <= 1) {
            throw ValidationException::withMessages([
                'password' => __("Sei l'unico super amministratore: assegna il ruolo a un altro utente prima di eliminare il tuo account."),
            ]);
        }

        Auth::guard('web')->logout();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
