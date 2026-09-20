<?php

namespace Tests\Feature\User;

use App\Models\Knowledge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `/api/v1/user/knowledge/getCategory` was routed to a method that did not
 * exist, so the user SPA's Knowledge page got a 500 on every load.
 */
class UserKnowledgeCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_categories_of_articles_they_can_read(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->makeKnowledge('kb-b', 'catB', 'zh-CN', 2);
        $this->makeKnowledge('kb-a', 'catA', 'zh-CN', 1);
        // Duplicate category: the endpoint returns each category once.
        $this->makeKnowledge('kb-a2', 'catA', 'zh-CN', 3);
        // Hidden and other-language articles must not leak their categories.
        $this->makeKnowledge('kb-hidden', 'catHidden', 'zh-CN', 4, false);
        $this->makeKnowledge('kb-english', 'catEnglish', 'en', 5);

        $response = $this->getJson('/api/v1/user/knowledge/getCategory?language=zh-CN');

        $response->assertOk();
        $this->assertSame(['catA', 'catB'], $response->json('data'));
    }

    private function makeKnowledge(
        string $title,
        string $category,
        string $language,
        int $sort,
        bool $show = true,
    ): Knowledge {
        return Knowledge::create([
            'title' => $title,
            'category' => $category,
            'language' => $language,
            'body' => '<p>x</p>',
            'show' => $show ? 1 : 0,
            'sort' => $sort,
        ]);
    }

    private function makeUser(): User
    {
        return User::create([
            'email' => 'knowledge-reader@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-0000000000aa',
            'token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaab',
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
