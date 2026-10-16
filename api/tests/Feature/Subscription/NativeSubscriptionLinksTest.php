<?php

namespace Tests\Feature\Subscription;

use App\Domains\Subscription\Services\NativeCredentialSynchronization;
use App\Domains\Subscription\Services\NativeSubscriptionLinks;
use App\Domains\CredentialProvider\RenderGrantCodec;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeRuntimeTestCase;

final class NativeSubscriptionLinksTest extends NativeRuntimeTestCase
{
    public function test_issue_delivers_bearer_once_and_replay_never_stores_or_reveals_it(): void
    {
        [$user, $subject] = $this->binding();
        $service = app(NativeSubscriptionLinks::class);
        $expiry = time() + 3600;
        $first = $service->issue($user->id, $subject->id, $expiry, 'link-issue');
        $this->assertTrue($first['token_delivered']);
        $this->assertMatchesRegularExpression('/\Aslt_[A-Za-z0-9_-]{43}\z/D', $first['link_token']);
        $row = DB::table('tx_subscription_link')->first();
        $this->assertSame(hash('sha256', $first['link_token']), $row->bearer_hash);
        $this->assertStringNotContainsString($first['link_token'], json_encode((array) $row));
        $replay = $service->issue($user->id, $subject->id, $expiry, 'link-issue');
        $this->assertNull($replay['link_token']);
        $this->assertFalse($replay['token_delivered']);
        $this->assertSame($first['link_ref'], $replay['link_ref']);
        $this->assertArrayNotHasKey('bearer_hash', $service->listing($user->id)['items'][0]);
        $this->expectExceptionMessage('IDEMPOTENCY_CONFLICT');
        $service->issue($user->id, $subject->id, $expiry + 1, 'link-issue');
    }

    public function test_link_revocation_is_independent_and_monotonic(): void
    {
        [$user, $subject] = $this->binding();
        $service = app(NativeSubscriptionLinks::class);
        $first = $service->issue($user->id, $subject->id, time() + 3600, 'link-issue');
        $revoked = $service->revoke($user->id, $first['link_ref']);
        $this->assertSame('revoked', $revoked['status']);
        $this->assertSame($revoked, $service->revoke($user->id, $first['link_ref']));
        $this->assertSame('active', DB::table('tx_access_subject')->where('id', $subject->id)->value('status'));
        $this->expectExceptionMessage('NATIVE_SUBSCRIPTION_LINK_MISSING');
        $service->redeem($first['link_ref'], $first['link_token'], $this->recipient());
    }

    public function test_another_owner_cannot_revoke_the_link(): void
    {
        [$user, $subject] = $this->binding();
        [$other] = $this->subscribe();
        $service = app(NativeSubscriptionLinks::class);
        $first = $service->issue($user->id, $subject->id, time() + 3600, 'link-issue');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('NATIVE_SUBSCRIPTION_LINK_MISSING');
        $service->revoke($other->id, $first['link_ref']);
    }

    public function test_creation_refuses_unbounded_expiry_and_foreign_subject(): void
    {
        [$user, $subject] = $this->binding();
        $this->expectExceptionMessage('NATIVE_SUBSCRIPTION_LINK_INPUT_INVALID');
        app(NativeSubscriptionLinks::class)->issue($user->id, $subject->id, time() + 366 * 86400, 'link-issue');
    }

    public function test_real_http_redemption_is_recipient_bound_finite_and_does_not_relay_secrets(): void
    {
        [$user, $subject] = $this->binding();
        $service = app(NativeSubscriptionLinks::class);
        $expiry = time() + 20;
        $link = $service->issue($user->id, $subject->id, $expiry, 'http-link');
        $recipient = $this->recipient();
        $response = $this->withHeader('Authorization', 'Bearer '.$link['link_token'])
            ->postJson('/txapi/subscription-links/'.$link['link_ref'].'/redeem', ['recipient_public_key' => $recipient])
            ->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $authorization = $response->json('data.authorization');
        $raw = base64_decode(strtr($authorization['payload'], '-_', '+/'), true);
        $this->assertNotFalse($raw);
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($recipient, $payload['recipient_public_key']);
        $this->assertSame((string) $subject->id, $payload['subject']['access_subject_id']);
        $this->assertLessThanOrEqual($expiry, $payload['expires_at']);
        $this->assertLessThanOrEqual(time() + 30, $payload['expires_at']);
        $this->assertStringNotContainsString($link['link_token'], $response->getContent());
        $this->assertArrayNotHasKey('uuid', $payload);
        $this->assertArrayNotHasKey('private_key', $payload);
        $this->assertArrayNotHasKey('configuration', $payload);
    }

    public function test_revocation_during_unlocked_metadata_request_prevents_a_new_render_grant(): void
    {
        [$user, $subject] = $this->binding();
        $service = app(NativeSubscriptionLinks::class);
        $link = $service->issue($user->id, $subject->id, time() + 3600, 'racing-link');
        $this->duringMetadata = function () use ($service, $user, $link): void {
            $this->assertSame(0, DB::transactionLevel());
            $service->revoke($user->id, $link['link_ref']);
        };
        $this->expectExceptionMessage('NATIVE_SUBSCRIPTION_LINK_MISSING');
        $service->redeem($link['link_ref'], $link['link_token'], $this->recipient());
    }

    public function test_link_redeeming_rechecks_the_current_account_entitlement(): void
    {
        [$user, $subject] = $this->binding();
        $service = app(NativeSubscriptionLinks::class);
        $link = $service->issue($user->id, $subject->id, time() + 3600, 'banned-link');
        $user->update(['banned' => true]);
        $this->expectExceptionMessage('NATIVE_SUBSCRIPTION_DELIVERY_ENTITLEMENT_DENIED');
        $service->redeem($link['link_ref'], $link['link_token'], $this->recipient());
    }

    public function test_link_redeeming_never_includes_a_currently_hidden_node(): void
    {
        [$user, $subject] = $this->binding();
        $service = app(NativeSubscriptionLinks::class);
        $link = $service->issue($user->id, $subject->id, time() + 3600, 'hidden-node-link');
        DB::table('tx_server')->where('id', $this->node->id)->update(['show' => false]);
        $this->expectExceptionMessage('NATIVE_SUBSCRIPTION_DELIVERY_NODES_UNAVAILABLE');
        $service->redeem($link['link_ref'], $link['link_token'], $this->recipient());
    }

    private function recipient(): string
    {
        return RenderGrantCodec::base64url(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
    }

    private function binding(): array
    {
        DB::table('tx_server')->where('id', $this->node->id)->update(['show' => true]);
        $this->activate();
        [$user, $subscription] = $this->subscribe();
        app(NativeCredentialSynchronization::class)->recover(100);
        $subject = DB::table('tx_access_subject')->where('subscription_id', $subscription['subscription_id'])
            ->where('status', 'active')->first();
        $this->assertNotNull($subject);
        return [$user, $subject];
    }
}
