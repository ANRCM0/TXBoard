<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminEntryRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_secure_path_serves_the_admin_spa_without_redirecting_to_admin(): void
    {
        $securePath = (string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );

        $this->get('/' . $securePath)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('id="root"', false);

        $this->get('/' . $securePath . '/config/system')
            ->assertOk()
            ->assertSee('id="root"', false);

        $this->get('/admin')->assertNotFound();
        $this->get('/admin/')->assertNotFound();
        $this->get('/admin/sign-in')->assertNotFound();
    }

    public function test_admin_favicon_is_rendered_safely_on_login_and_nested_pages(): void
    {
        $path = 'custom-admin-entry';
        admin_setting(['secure_path' => $path]);
        $this->get('/' . $path)->assertOk()
            ->assertDontSee('id="txboard-admin-favicon"', false);
        admin_setting(['admin_favicon_url' => 'https://images.example.test/favicon.ico?a=1&b=2']);
        foreach (['/' . $path, '/' . $path . '/sign-in', '/' . $path . '/config/system'] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('id="txboard-admin-favicon"', false)
                ->assertSee('href="https://images.example.test/favicon.ico?a=1&amp;b=2"', false)
                ->assertSee('referrerpolicy="no-referrer"', false);
        }
        admin_setting(['admin_favicon_url' => 'javascript:alert(1)']);
        $this->get('/' . $path)->assertOk()
            ->assertDontSee('id="txboard-admin-favicon"', false)
            ->assertDontSee('javascript:alert(1)', false);
    }

    public function test_rotating_secure_path_invalidates_the_old_entry_immediately(): void
    {
        $oldPath = (string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );
        $newPath = 'rotated-admin-entry';

        admin_setting(['secure_path' => $newPath]);

        $this->get('/' . $newPath)->assertOk();
        $this->get('/' . $newPath . '/sign-in')->assertOk();
        $this->get('/' . $oldPath)->assertNotFound();
        $this->get('/' . $oldPath . '/config/system')->assertNotFound();
    }
}
