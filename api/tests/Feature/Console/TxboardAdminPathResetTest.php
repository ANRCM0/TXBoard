<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TxboardAdminPathResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_uninstalled_panel_rejects_rotation_and_keeps_existing_path(): void
    {
        admin_setting(['secure_path' => 'original_admin_entry']);

        $this->artisan('txboard:admin-path-reset')->assertFailed();
        $this->assertSame('original_admin_entry', (string) admin_setting('secure_path'));
    }

    public function test_rotating_path_persists_a_random_32_digit_hex_segment_and_revokes_old_entry(): void
    {
        User::create([
            'email' => 'operator@example.test',
            'password' => password_hash('testing-password', PASSWORD_DEFAULT),
            'uuid' => (string) Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'is_admin' => 1,
            'banned' => 0,
        ]);
        admin_setting(['secure_path' => 'original_admin_entry']);

        $this->assertSame(0, Artisan::call('txboard:admin-path-reset'));
        $first = (string) admin_setting('secure_path');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $first);
        $this->assertNotSame('original_admin_entry', $first);
        $this->assertStringContainsString('/' . $first . '/', Artisan::output());

        $this->get('/original_admin_entry/')->assertNotFound();
        $this->get('/' . $first . '/')->assertOk();
        $this->getJson('/txapi/admin/original_admin_entry/settings')->assertNotFound();
        $this->getJson('/txapi/admin/' . $first . '/settings')->assertForbidden();

        // Repeated recovery must rotate again and invalidate the first path.
        $this->assertSame(0, Artisan::call('txboard:admin-path-reset'));
        $second = (string) admin_setting('secure_path');
        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $second);
        $this->get('/' . $first . '/')->assertNotFound();
        $this->get('/' . $second . '/')->assertOk();
    }
}
