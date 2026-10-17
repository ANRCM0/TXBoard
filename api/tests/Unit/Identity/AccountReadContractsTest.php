<?php

namespace Tests\Unit\Identity;

use App\Domains\Identity\Contracts\AccountIdentity;
use App\Domains\Identity\NativeAccountQueries;
use App\Domains\Subscription\Contracts\EntitlementQueries;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

final class AccountReadContractsTest extends TestCase
{
    public function test_actor_projection_is_readonly_scalar_data_not_a_new_email_or_telegram_policy(): void
    {
        $identity = new AccountIdentity(7, 'existing-identity-value', -42);
        $this->assertSame(['userId' => 7, 'email' => 'existing-identity-value', 'telegramId' => -42],
            get_object_vars($identity));
        $this->assertNull((new AccountIdentity(8, 'other@example.test', null))->telegramId);
        $this->assertTrue((new \ReflectionClass(AccountIdentity::class))->isReadOnly());
    }

    public function test_actor_projection_rejects_nonpositive_ids(): void
    {
        foreach ([0, -1] as $id) {
            try {
                new AccountIdentity($id, 'account@example.test', null);
                $this->fail('Nonpositive actor identity was accepted.');
            } catch (InvalidArgumentException $error) {
                $this->assertSame('ACCOUNT_IDENTITY_INVALID', $error->getMessage());
            }
        }
    }

    public function test_subscription_input_is_validated_before_calling_the_entitlement_port(): void
    {
        $entitlements = Mockery::mock(EntitlementQueries::class);
        $entitlements->shouldNotReceive('forAccount');
        $queries = new NativeAccountQueries($entitlements);
        foreach ([0, -1] as $id) {
            try {
                $queries->subscription($id);
                $this->fail('Nonpositive subscription owner was accepted.');
            } catch (InvalidArgumentException $error) {
                $this->assertSame('ENTITLEMENT_INPUT_INVALID', $error->getMessage());
            }
        }
    }
}
