<?php

namespace Tests\Feature\Subscription;

use App\Models\User;
use App\Services\Auth\NativeIdentityService;
use App\Services\Subscription\NativePlanCatalog;
use App\Services\Subscription\PlanPublicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeSubscriptionTestCase;

final class NativeIdentityAndCatalogTest extends NativeSubscriptionTestCase
{
    public function test_native_identity_creation_does_not_issue_legacy_credentials_or_jobs(): void
    {
        [$success, $user] = app(NativeIdentityService::class)->create('native-identity@example.test', 'native-password');
        $this->assertTrue($success);
        $this->assertTrue(password_verify('native-password', $user->password));
        $this->assertSame('', $user->uuid);
        $this->assertSame('', $user->token);
        $this->assertNull($user->plan_id);
        $this->assertNull($user->group_id);
        $this->assertSame(0, (int) $user->transfer_enable);
        $this->assertSame(0, (int) $user->balance);
        Bus::assertNothingDispatched();
    }

    public function test_native_registration_cannot_inject_roles_or_trial_entitlements(): void
    {
        $this->settings['try_out_plan_id'] = 99;
        $request = Request::create('/txapi/auth/register', 'POST', [
            'email' => 'native-role@example.test', 'password' => 'native-password',
            'is_admin' => true, 'balance' => 999, 'plan_id' => 99,
        ]);
        [$success, $user] = app(NativeIdentityService::class)->register($request);
        $this->assertTrue($success);
        $this->assertFalse($user->is_admin);
        $this->assertNull($user->plan_id);
        $this->assertSame(0, (int) $user->balance);
    }

    public function test_native_registration_refuses_unsupported_invitations(): void
    {
        [$success] = app(NativeIdentityService::class)->register(Request::create('/txapi/auth/register', 'POST', [
            'email' => 'native-invite@example.test', 'password' => 'native-password', 'invite_code' => 'unsupported',
        ]));
        $this->assertFalse($success);
        $this->assertSame(0, User::query()->count());
    }

    public function test_published_catalog_does_not_read_later_plan_edits_or_legacy_user_counters(): void
    {
        $plan = $this->plan();
        $offer = $this->publishedOffer($plan);
        DB::table('tx_plan')->where('id', $plan->id)->update(['name' => 'Edited later', 'prices' => '{"monthly":"999.00"}']);
        $this->user(['plan_id' => $plan->id, 'group_id' => 99, 'transfer_enable' => 999999]);
        $rows = app(NativePlanCatalog::class)->available($plan->id);
        $monthly = collect($rows)->firstWhere('offer_id', (string) $offer);
        $this->assertNotNull($monthly);
        $this->assertSame('Native fixture plan', $monthly['name']);
        $this->assertSame('1234', $monthly['price_minor']);
        $this->assertSame('2147483648', $monthly['quota_bytes']);
    }

    public function test_unpublished_availability_is_not_a_saleable_offer(): void
    {
        $offer = $this->publishedOffer($this->plan());
        app(PlanPublicationService::class)->setAvailability($offer,
            ['show' => false, 'sell' => true, 'renew' => true, 'capacity_limit' => null], 1);
        $this->assertNotContains((string) $offer, array_column(app(NativePlanCatalog::class)->available(), 'offer_id'));
    }
}
