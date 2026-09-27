<?php

namespace Tests\Feature;

use Tests\TestCase;

/** In the production image the React app shell (public/app.html) is served for every page route. */
class SpaFallbackTest extends TestCase
{
    private string $shell;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shell = public_path('app.html');
        file_put_contents($this->shell, '<!doctype html><div id="root"></div>');
    }

    protected function tearDown(): void
    {
        @unlink($this->shell);
        parent::tearDown();
    }

    public function test_page_routes_return_the_app_shell(): void
    {
        foreach (['/', '/programs', '/admin/programs/abc'] as $path) {
            $response = $this->get($path)->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
            $this->assertSame($this->shell, $response->baseResponse->getFile()->getPathname());
        }
    }

    public function test_api_and_missing_files_are_not_swallowed(): void
    {
        $this->getJson('/api/v1/does-not-exist')->assertNotFound();
        $this->get('/assets/old-build.js')->assertNotFound();
        $this->post('/programs')->assertStatus(405);
    }
}
