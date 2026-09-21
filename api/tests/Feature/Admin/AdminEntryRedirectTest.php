<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminEntryRedirectTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The historical /{secure_path} entry point used to render a Blade shell
     * that loaded /assets/admin/*, which TXBoard no longer builds. It must keep
     * the URL working by handing off to the admin SPA instead.
     */
    public function test_legacy_secure_path_redirects_to_admin_spa(): void
    {
        $securePath = admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );

        $this->get('/' . $securePath)->assertRedirect('/admin/');
    }
    public function test_legacy_admin_entry_uses_the_rotated_secure_path_immediately(): void
    {
        $oldPath = (string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );
        $newPath = 'rotated-admin-entry';

        admin_setting(['secure_path' => $newPath]);

        $this->get('/' . $newPath)->assertRedirect('/admin/');
        $this->get('/' . $oldPath)->assertNotFound();
    }

}
