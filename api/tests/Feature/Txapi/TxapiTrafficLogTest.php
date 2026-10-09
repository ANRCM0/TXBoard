<?php

namespace Tests\Feature\Txapi;

use App\Models\StatUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiTrafficLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_traffic_requires_user_and_is_current_month_owner_scoped_and_paginated(): void
    {
        $this->getJson('/txapi/traffic/logs')->assertStatus(401);
        $owner = $this->user('traffic-owner@example.test');
        $other = $this->user('traffic-other@example.test');
        $this->record($owner, now()->startOfMonth()->timestamp + 100, 10);
        $this->record($owner, now()->startOfMonth()->timestamp + 200, 20);
        $this->record($owner, now()->startOfMonth()->subSecond()->timestamp, 99);
        $this->record($other, now()->startOfMonth()->timestamp + 300, 777);
        Sanctum::actingAs($owner);
        $result = $this->getJson('/txapi/traffic/logs?per_page=1&page=1');
        $result->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.upload_bytes', 20)
            ->assertJsonPath('data.0.download_bytes', 40)
            ->assertJsonCount(1, 'data');
        $this->assertArrayNotHasKey('user_id', $result->json('data.0'));
        $result->assertJsonMissing(['upload_bytes' => 777]);
        $this->getJson('/txapi/traffic/logs?per_page=1&page=2')
            ->assertOk()->assertJsonPath('data.0.upload_bytes', 10);
    }

    public function test_bad_page_arguments_are_rejected(): void
    {
        Sanctum::actingAs($this->user('traffic-params@example.test'));
        foreach (['page=0', 'per_page=0', 'per_page=101', 'page=abc'] as $query) {
            $this->getJson('/txapi/traffic/logs?' . $query)->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
    }

    private function user(string $email): User
    {
        return User::create([
            'email' => $email, 'password' => 'hash',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'banned' => 0,
        ]);
    }

    private function record(User $user, int $at, int $upload): void
    {
        StatUser::create([
            'user_id' => $user->id, 'u' => $upload, 'd' => $upload * 2,
            'record_at' => $at, 'server_rate' => 1,
        ]);
    }
}
