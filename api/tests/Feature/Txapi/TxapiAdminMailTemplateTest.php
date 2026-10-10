<?php

namespace Tests\Feature\Txapi;

use App\Models\MailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TxapiAdminMailTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/txapi/admin/mail_admin_secret/mail-templates';

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['secure_path' => 'mail_admin_secret']);
    }

    public function test_mail_template_admin_auth_and_rotating_path_are_enforced(): void
    {
        $this->getJson(self::ROOT)->assertStatus(403);
        Sanctum::actingAs($this->account('mail-user@example.test'));
        $this->getJson(self::ROOT)->assertStatus(403);
        $this->putJson(self::ROOT . '/verify', ['subject' => 'Test',
            'content' => '{{code}}'])->assertStatus(403);

        Sanctum::actingAs($this->account('mail-admin@example.test', true));
        $this->getJson('/txapi/admin/guessed/mail-templates')->assertStatus(404);
        $this->getJson(self::ROOT)->assertOk()
            ->assertJsonPath('data.0.name', 'verify');
        $this->getJson(self::ROOT . '/does-not-exist')->assertStatus(404);
        $this->getJson(self::ROOT . '/verify')->assertOk()
            ->assertJsonPath('data.name', 'verify')
            ->assertJsonPath('data.customized', false);
    }

    public function test_save_enforces_placeholders_and_reset_restores_default(): void
    {
        Sanctum::actingAs($this->account('mail-editor@example.test', true));
        $this->putJson(self::ROOT . '/verify', [
            'subject' => 'Custom code',
            'content' => '<p>Missing required code</p>',
        ])->assertStatus(422);

        Cache::put('mail_template:verify', 'old-cache', 3600);
        $this->putJson(self::ROOT . '/verify', [
            'subject' => 'Custom code',
            'content' => '<p>{{code}}</p>',
        ])->assertOk()->assertJsonPath('data.ok', true);
        $this->assertDatabaseHas('tx_mail_templates', [
            'name' => 'verify', 'subject' => 'Custom code',
        ]);
        $this->assertNull(Cache::get('mail_template:verify'));
        $this->getJson(self::ROOT . '/verify')->assertOk()
            ->assertJsonPath('data.customized', true)
            ->assertJsonPath('data.content', '<p>{{code}}</p>');

        $this->deleteJson(self::ROOT . '/verify')->assertOk()
            ->assertJsonPath('data.ok', true);
        $this->assertSame(0, MailTemplate::query()->count());
        $this->getJson(self::ROOT . '/verify')->assertOk()
            ->assertJsonPath('data.customized', false);
    }

    public function test_invalid_test_email_is_rejected_before_delivery(): void
    {
        Sanctum::actingAs($this->account('mail-test-admin@example.test', true));
        $this->postJson(self::ROOT . '/notify/test', [
            'email' => 'not-an-email',
        ])->assertStatus(422);
        $this->postJson(self::ROOT . '/unknown/test')->assertStatus(404);
    }

    private function account(string $email, bool $admin = false): User
    {
        return User::create([
            'email' => $email,
            'password' => 'testing-pass',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'is_admin' => $admin, 'banned' => 0,
        ]);
    }
}
