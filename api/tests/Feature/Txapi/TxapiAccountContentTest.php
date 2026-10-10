<?php

namespace Tests\Feature\Txapi;

use App\Models\Knowledge;
use App\Models\Notice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiAccountContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_account_returns_whitelisted_profile_with_finance_and_expiry(): void
    {
        $user = $this->user('account@example.test');
        $user->update([
            'balance' => 1250, 'commission_balance' => 675,
            'transfer_enable' => 1073741824, 'u' => 10, 'd' => 15,
            'expired_at' => time() + 86400,
        ]);
        Sanctum::actingAs($user);
        $response = $this->getJson('/txapi/me');
        $response->assertOk()->assertJsonPath('data.balance_minor', 1250)
            ->assertJsonPath('data.commission_balance_minor', 675)
            ->assertJsonPath('data.uuid', $user->uuid)
            ->assertJsonPath('data.traffic.limit_bytes', 1073741824);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/',
            $response->json('data.expired_at'));
        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertArrayNotHasKey('token', $response->json('data'));
        $this->assertArrayNotHasKey('password_salt', $response->json('data'));
    }

    public function test_notice_pagination_only_exposes_visible_articles_and_validates_bounds(): void
    {
        Sanctum::actingAs($this->user('notice@example.test'));
        Notice::create(['title' => 'First', 'content' => 'Hello',
            'sort' => 1, 'show' => true, 'img_url' => null]);
        Notice::create(['title' => 'Second', 'content' => 'World',
            'sort' => 2, 'show' => true, 'img_url' => null]);
        Notice::create(['title' => 'Hidden', 'content' => 'private',
            'sort' => 0, 'show' => false, 'img_url' => null]);

        $page = $this->getJson('/txapi/notices?page=2&per_page=1');
        $page->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('data.0.title', 'Second');
        $this->assertStringNotContainsString('private', $page->getContent());
        $this->getJson('/txapi/notices?per_page=101')->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_knowledge_hides_drafts_and_masks_access_for_inactive_subscribers(): void
    {
        $user = $this->user('reader@example.test');
        Sanctum::actingAs($user);
        Knowledge::create(['title' => 'Visible', 'category' => 'Start',
            'language' => 'zh-CN', 'sort' => 1, 'show' => 1,
            'body' => 'Before <!--access start-->SECRET<!--access end--> After']);
        Knowledge::create(['title' => 'Hidden', 'category' => 'Private',
            'language' => 'zh-CN', 'sort' => 2, 'show' => 0, 'body' => 'Hidden']);
        Knowledge::create(['title' => 'English', 'category' => 'EN',
            'language' => 'en', 'sort' => 3, 'show' => 1, 'body' => 'English']);

        $response = $this->getJson('/txapi/knowledge?language=zh-CN');
        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category', 'Start');
        $this->assertStringNotContainsString('SECRET', $response->getContent());
        $this->assertStringNotContainsString('Private', $response->getContent());
        $this->getJson('/txapi/knowledge/categories?language=zh-CN')
            ->assertOk()->assertJsonPath('data.0', 'Start');
        $this->getJson('/txapi/knowledge?language=zh-CN&keyword=NoMatch')
            ->assertOk()->assertJsonCount(0, 'data');

        $user->update(['transfer_enable' => 1073741824,
            'expired_at' => time() + 3600]);
        $this->getJson('/txapi/knowledge?language=zh-CN')
            ->assertOk()->assertSeeText('SECRET');
    }

    public function test_content_requires_user_and_native_errors_stay_scoped(): void
    {
        $this->getJson('/txapi/notices')->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->getJson('/txapi/knowledge')->assertStatus(401);
        $this->getJson('/txapi/knowledge/categories')->assertStatus(401);
        $this->getJson('/txapi/knowledge/100')->assertStatus(401);
        Sanctum::actingAs($this->user('missing@example.test'));
        $this->getJson('/txapi/knowledge/999')->assertStatus(404);
        $this->getJson('/txapi/knowledge?language=' . str_repeat('x', 11))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    private function user(string $email): User
    {
        return User::create([
            'email' => $email, 'password' => 'secret-test-only',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'balance' => 0, 'commission_balance' => 0,
            'transfer_enable' => 0, 'u' => 0, 'd' => 0,
            'banned' => 0, 'expired_at' => 0,
        ]);
    }
}
