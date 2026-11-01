<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Lists and terminates the user's other browser sessions.
 * Requires SESSION_DRIVER=database.
 */
class BrowserSessionController extends Controller
{
    /**
     * @return list<array{id: string, ip_address: string|null, is_current_device: bool, device: array{browser: string, platform: string, desktop: bool}, last_active: string}>
     */
    public function sessions(Request $request): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn (object $session) => [
                'id' => hash('xxh128', $session->id),
                'ip_address' => $session->ip_address,
                'is_current_device' => $session->id === $request->session()->getId(),
                'device' => $this->parseUserAgent((string) $session->user_agent),
                'last_active' => Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
            ])
            ->values()
            ->all();
    }

    /**
     * Log out from every other browser session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        Auth::guard('web')->logoutOtherDevices($request->input('password'));

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $request->user()->getAuthIdentifier())
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Disconnesso dalle altre sessioni.')]);

        return back();
    }

    /**
     * Minimal user agent parsing, good enough for a session list.
     *
     * @return array{browser: string, platform: string, desktop: bool}
     */
    private function parseUserAgent(string $userAgent): array
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => __('Sconosciuto'),
        };

        $platform = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Mac OS') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => __('Sconosciuto'),
        };

        return [
            'browser' => $browser,
            'platform' => $platform,
            'desktop' => ! in_array($platform, ['iOS', 'Android'], true),
        ];
    }
}
