<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Tests\TestCase;

class ReverseProxyTest extends TestCase
{
    private const FORWARDED = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'prugna.reimg.it', 'X-Forwarded-Port' => '443'];

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    public function test_behind_a_trusted_proxy_links_use_the_public_https_address(): void
    {
        AppServiceProvider::trustProxies('*');

        $this->get('/dashboard', self::FORWARDED)->assertRedirect('https://prugna.reimg.it/login');
    }

    public function test_without_trusted_proxies_the_forwarded_headers_are_ignored(): void
    {
        AppServiceProvider::trustProxies(null);

        $location = $this->get('/dashboard', self::FORWARDED)->assertRedirect()->headers->get('Location');

        $this->assertStringStartsNotWith('https://prugna.reimg.it', (string) $location);
    }
}
