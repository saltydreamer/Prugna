<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;

class SendPasswordResetLinkController extends Controller
{
    /**
     * Email the user a link to choose a new password.
     */
    public function __invoke(User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $status = Password::sendResetLink(['email' => $user->email]);

        Inertia::flash('toast', $status === Password::RESET_LINK_SENT
            ? ['type' => 'success', 'message' => __('Link per reimpostare la password inviato a :email.', ['email' => $user->email])]
            : ['type' => 'error', 'message' => __($status)]);

        return back();
    }
}
