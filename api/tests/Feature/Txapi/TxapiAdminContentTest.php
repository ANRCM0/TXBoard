<?php

namespace Tests\Feature\Txapi;

use App\Models\AdminAuditLog;
use App\Models\Knowledge;
use App\Models\Notice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiAdminContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        admin_setting(['secure_path' => 'content_admin_secret']);
    }

    public function test_content_endpoints_require_admin_and_current_secure_path(): void
    {
        $root = '/txapi/admin/content_admin_secret/content';
        $this->getJson($root . '/notices')->assertStatus(403);
        $this->postJson($root . '/knowledge', [
            'title' => 'Cannot create', 'body' => 'x', 'category' => 'x', 'language' => 'zh-CN',
        ])->assertStatus(403);
        Sanctum::actingAs($this->user('reader@example.test', false));
        $this->getJson($root . '/knowledge')->assertStatus(403);
        $this->deleteJson($root . '/notices/1')->assertStatus(403);
        Sanctum::actingAs($this->user('editor@example.test', true));
        $this->getJson('/txapi/admin/incorrect/content/notices')->assertStatus(404);
        $this->getJson($root . '/notices')->assertOk();
        admin_setting(['secure_path' => 'rotated_content_path']);
        $this->getJson($root . '/knowledge')->assertStatus(404);
        $this->getJson('/txapi/admin/rotated_content_path/content/knowledge')->assertOk();
    }

    public function test_notice_crud_filter_visibility_sort_and_mutating_audit(): void
    {
        Sanctum::actingAs($this->user('notice-admin@example.test', true));
        $base = '/txapi/admin/content_admin_secret/content/notices';
        $payload = ['title' => 'Welcome bulletin', 'content' => '<p>Welcome</p>',
            'tags' => ['news'], 'show' => true, 'popup' => false];
        $id = $this->postJson($base, $payload)
            ->assertStatus(201)->json('data.id');
        $this->assertIsInt($id);
        $other = Notice::create(['title' => 'Other news', 'content' => 'Other',
            'sort' => 10, 'show' => true]);
        $filtered = $this->getJson($base . '?page=1&per_page=1&title=Welcome')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.tags.0', 'news');
        $this->assertStringNotContainsString('server_token', $filtered->getContent());
        $this->putJson($base . '/' . $id,
            ['title' => 'Updated bulletin', 'content' => 'Updated text', 'show' => false])
            ->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame('Updated bulletin', Notice::findOrFail($id)->title);
        $this->patchJson($base . '/' . $id . '/visibility')
            ->assertOk()->assertJsonPath('data.show', true);
        $this->putJson($base . '/sort', ['ids' => [$other->id, $id]])
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(1, (int) $other->fresh()->sort);
        $this->assertSame(2, (int) Notice::findOrFail($id)->sort);
        $this->putJson($base . '/sort', ['ids' => [$other->id, 999999]])
            ->assertStatus(404);
        $this->assertSame(1, (int) $other->fresh()->sort);
        $this->putJson($base . '/sort', ['ids' => [$other->id, $other->id]])
            ->assertStatus(422);
        $this->deleteJson($base . '/' . $id)->assertOk()
            ->assertJsonPath('data.ok', true);
        $this->assertNull(Notice::find($id));

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            $this->assertTrue(AdminAuditLog::where('method', $verb)->exists(),
                $verb . ' admin writes must produce an audit event');
        }
        $this->postJson($base, ['title' => '', 'content' => ''])->assertStatus(422);
        $this->getJson($base . '?per_page=101')->assertStatus(422);
    }

    public function test_knowledge_drafts_categories_detail_filters_and_sorting(): void
    {
        Sanctum::actingAs($this->user('knowledge-admin@example.test', true));
        $base = '/txapi/admin/content_admin_secret/content/knowledge';
        $id = $this->postJson($base, [
            'title' => 'Draft troubleshooting', 'category' => 'guides',
            'language' => 'zh-CN', 'body' => 'Private draft', 'show' => false,
        ])->assertStatus(201)->json('data.id');
        $this->postJson($base, [
            'title' => 'Public FAQ', 'category' => 'faq',
            'language' => 'zh-CN', 'body' => 'FAQ body', 'show' => true,
        ])->assertStatus(201);
        $this->getJson($base . '?title=troubleshooting&category=guides')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.show', false);
        $this->getJson($base . '/categories')->assertOk()
            ->assertJsonPath('data.0', 'faq')->assertJsonPath('data.1', 'guides');
        $this->getJson($base . '/' . $id)->assertOk()
            ->assertJsonPath('data.body', 'Private draft');
        $this->putJson($base . '/' . $id, [
            'title' => 'Updated', 'category' => 'guides',
            'language' => 'en', 'body' => 'Updated body', 'show' => true,
        ])->assertOk();
        $this->assertSame('Updated body', Knowledge::findOrFail($id)->body);
        $this->patchJson($base . '/' . $id . '/visibility')
            ->assertOk()->assertJsonPath('data.show', false);
        $ids = Knowledge::orderBy('id')->pluck('id')->all();
        $this->putJson($base . '/sort', ['ids' => array_reverse($ids)])
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(1, (int) Knowledge::findOrFail($ids[1])->sort);
        $this->deleteJson($base . '/' . $id)->assertOk();
        $this->getJson($base . '/' . $id)->assertStatus(404);
        $this->putJson($base . '/sort', ['ids' => [$id]])->assertStatus(404);
        $this->postJson($base, ['title' => 'Incomplete'])->assertStatus(422);
        $this->getJson($base . '?page=0')->assertStatus(422);
    }

    private function user(string $email, bool $admin): User
    {
        return User::create([
            'email' => $email, 'password' => 'test-only-hash',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'balance' => 0,
            'commission_balance' => 0, 'is_admin' => $admin, 'banned' => 0,
        ]);
    }
}
