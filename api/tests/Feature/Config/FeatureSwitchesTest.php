<?php

namespace Tests\Feature\Config;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeatureSwitchesTest extends TestCase
{
    use RefreshDatabase;

    private const SWITCHES = [
        'invite_enable',
        'commission_enable',
        'gift_card_enable',
        'coupon_enable',
        'ticket_enable',
        'knowledge_enable',
        'traffic_log_enable',
        'announcement_enable',
        'register_enable',
    ];

    public function test_unset_feature_switches_default_to_enabled(): void
    {
        $switches = admin_feature_switches();

        foreach (self::SWITCHES as $key) {
            $this->assertArrayHasKey($key, $switches);
            $this->assertSame(1, $switches[$key], "{$key} should default to enabled");
        }
    }

    public function test_persisted_feature_switches_are_returned_disabled(): void
    {
        admin_setting(['ticket_enable' => 0, 'gift_card_enable' => 0]);

        $switches = admin_feature_switches();

        $this->assertSame(0, $switches['ticket_enable']);
        $this->assertSame(0, $switches['gift_card_enable']);
        $this->assertSame(1, $switches['invite_enable']);
    }

    /**
     * The user SPA only sees a switch if the config endpoints expose it, so the
     * helper alone is not enough to prove the entries can actually be hidden.
     */
    public function test_guest_comm_config_exposes_feature_switches(): void
    {
        admin_setting(['ticket_enable' => 0]);

        $response = $this->getJson('/api/v1/guest/comm/config');

        $response->assertOk();
        $this->assertSame(0, $response->json('data.ticket_enable'));
        $this->assertSame(1, $response->json('data.invite_enable'));
        $this->assertSame(1, $response->json('data.register_enable'));
    }

    /**
     * The admin form posts the switches to /config/save; this pins that the
     * request rules accept them and that the stored value reaches the SPA.
     */
    public function test_admin_can_persist_feature_switches_through_config_save(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $securePath = admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );

        $response = $this->postJson("/api/v2/{$securePath}/config/save", [
            'ticket_enable' => false,
            'invite_enable' => true,
        ]);

        $response->assertOk();
        $this->assertSame(0, admin_feature_switches()['ticket_enable']);
        $this->assertSame(1, admin_feature_switches()['invite_enable']);
    }

    private function makeAdmin(): User
    {
        return User::create([
            'email' => 'feature-switch-admin@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000000002',
            'token' => 'fedcba9876543210fedcba9876543210',
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
    }
}
