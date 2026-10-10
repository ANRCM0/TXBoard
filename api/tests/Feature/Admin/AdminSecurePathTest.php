<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSecurePathTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_admin_path_is_hidden_before_authentication(): void
    {
        $securePath = (string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );
        $wrongPath = $securePath . '-wrong';

        $this->getJson("/txapi/admin/{$wrongPath}/settings")
            ->assertNotFound();

        $this->getJson("/txapi/admin/{$securePath}/settings")
            ->assertForbidden();
    }
}
