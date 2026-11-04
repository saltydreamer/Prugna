<?php

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Installable app: web manifest and service worker (resources/js/sw.js), whose version follows the
 * build so that every deploy refreshes the cached files.
 */
class PwaController extends Controller
{
    public function manifest(): JsonResponse
    {
        return response()->json([
            'name' => config('app.name'),
            'short_name' => mb_substr((string) config('app.name'), 0, 12),
            'description' => __('Preventivi, fatture, interventi e spese.'),
            'lang' => 'it',
            'start_url' => '/dashboard?source=app',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#F8FAFC',
            'theme_color' => '#4F46E5',
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => __('Interventi sul campo'), 'short_name' => __('Sul campo'), 'url' => '/field', 'icons' => [['src' => '/icons/icon-192.png', 'sizes' => '192x192']]],
                ['name' => __('Interventi'), 'url' => '/interventions', 'icons' => [['src' => '/icons/icon-192.png', 'sizes' => '192x192']]],
                ['name' => __('Fatture'), 'url' => '/sales/invoices', 'icons' => [['src' => '/icons/icon-192.png', 'sizes' => '192x192']]],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function serviceWorker(): Response
    {
        $manifestPath = public_path('build/manifest.json');
        $manifest = is_file($manifestPath) ? (string) file_get_contents($manifestPath) : '';

        $script = strtr((string) file_get_contents(resource_path('js/sw.js')), [
            '__VERSION__' => substr(hash('sha256', $manifest.'|'.filemtime(resource_path('js/sw.js'))), 0, 16),
            '__ASSETS__' => json_encode($this->assets($manifest), JSON_UNESCAPED_SLASHES),
        ]);

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Service-Worker-Allowed' => '/',
        ]);
    }

    /**
     * Built files to keep offline: scripts, styles and the woff2 fonts (other font formats are never used).
     *
     * @return list<string>
     */
    private function assets(string $manifest): array
    {
        $files = [];

        foreach ((array) json_decode($manifest, true) as $chunk) {
            foreach ([$chunk['file'] ?? null, ...($chunk['css'] ?? []), ...($chunk['assets'] ?? [])] as $file) {
                if (is_string($file) && preg_match('/\.(js|css|woff2)$/', $file)) {
                    $files[] = '/build/'.$file;
                }
            }
        }

        return array_values(array_unique($files));
    }
}
