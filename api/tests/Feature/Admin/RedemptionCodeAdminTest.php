<?php

namespace Tests\Feature\Admin;

use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\GiftCardUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RedemptionCodeAdminTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::create([
            'email' => 'gift-admin@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000000091',
            'token' => str_repeat('a', 32),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 1,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        Sanctum::actingAs($admin);
        $path = (string) admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        $this->base = "/txapi/admin/{$path}/gift-cards";
    }

    private function template(): GiftCardTemplate
    {
        return GiftCardTemplate::create([
            'name' => 'redeem-test', 'type' => GiftCardTemplate::TYPE_GENERAL,
            'rewards' => ['balance' => 100], 'admin_id' => 1,
            'status' => 1, 'sort' => 0,
        ]);
    }

    public function test_legacy_admin_route_is_removed_and_native_list_is_available(): void
    {
        $this->getJson($this->base . '/templates')->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->getJson(str_replace('/txapi/admin/', '/api/v2/', $this->base)
            . '/templates')->assertNotFound();
    }

    public function test_issuance_rejects_disabled_templates_and_bounds_count(): void
    {
        $template = $this->template();
        $this->postJson($this->base . '/codes/batches', [
            'template_id' => $template->id, 'count' => 501,
        ])->assertStatus(422);
        $template->update(['status' => 0]);
        $this->postJson($this->base . '/codes/batches', [
            'template_id' => $template->id, 'count' => 1,
        ])->assertStatus(409);
        $this->assertSame(0, GiftCardCode::count());
    }

    public function test_issuance_and_export_return_same_batch_codes(): void
    {
        $template = $this->template();
        $result = $this->postJson($this->base . '/codes/batches', [
            'template_id' => $template->id, 'count' => 3,
        ])->assertCreated();
        $batch = $result->json('data.batch_id');
        $this->assertSame(3, GiftCardCode::where('batch_id', $batch)->count());
        $export = $this->get($this->base . '/codes/export?batch_id=' . urlencode($batch));
        $export->assertOk();
        $this->assertStringContainsString('redemption_codes.txt', $export->headers->get('content-disposition'));
    }

    public function test_used_code_cannot_be_reenabled_or_deleted(): void
    {
        $template = $this->template();
        $code = GiftCardCode::create([
            'template_id' => $template->id, 'code' => 'GC1234567890',
            'status' => GiftCardCode::STATUS_DISABLED,
            'usage_count' => 1, 'max_usage' => 2,
        ]);
        $this->patchJson($this->base . '/codes/' . $code->id . '/toggle', [
            'action' => 'enable',
        ])->assertStatus(409);
        $this->patchJson($this->base . '/codes/' . $code->id, [
            'max_usage' => 0,
        ])->assertStatus(422);
        $this->deleteJson($this->base . '/codes/' . $code->id)->assertStatus(409);
        $this->assertDatabaseHas('v2_gift_card_code', ['id' => $code->id]);
    }

    public function test_template_with_issued_codes_cannot_be_deleted(): void
    {
        $template = $this->template();
        GiftCardCode::create([
            'template_id' => $template->id, 'code' => 'GC1234567890',
            'status' => GiftCardCode::STATUS_UNUSED,
            'usage_count' => 0, 'max_usage' => 1,
        ]);
        $this->deleteJson($this->base . '/templates/' . $template->id)->assertStatus(409);
        $this->assertDatabaseHas('v2_gift_card_template', ['id' => $template->id]);
    }
}
