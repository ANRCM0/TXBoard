<?php

namespace Tests\Feature\Subscription;

use App\Domains\CredentialProvider\RenderGrantCodec;
use App\Domains\Subscription\Services\NativeCredentialSynchronization;
use App\Domains\Subscription\Services\NativeSubscriptionDelivery;
use App\Models\User;
use App\Services\Auth\NativeIdentityService;
use DomainException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\NativeRuntimeTestCase;

/** Deferred browser-owner acceptance: real admission, current sessions, binding evidence and signer. */
final class NativeBrowserDeliveriesTest extends NativeRuntimeTestCase
{
    private NativeSubscriptionDelivery $delivery;
    private PersonalAccessToken $sessionToken;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('tx_server')->where('id', $this->node->id)->update(['show' => true]);
        $this->activate(); $this->delivery = app(NativeSubscriptionDelivery::class);
    }

    public function test_discovery_has_only_public_trust_explicit_formats_and_owned_bound_subjects_without_network(): void
    {
        [$owner, $subject] = $this->binding(); $this->authenticateActor($owner);
        $this->duringMetadata = static function (): void { throw new \RuntimeException('DISCOVERY_MUST_NOT_CALL_METADATA'); };
        $options = $this->delivery->browserOptions($owner->id);
        $this->assertTrue($options['authorized']); $this->assertSame((string) $owner->id, $options['user_id']);
        $this->assertEqualsCanonicalizing(['protocol_version', 'core_instance_id', 'key_id', 'key_epoch', 'algorithm', 'public_key'], array_keys($options['trust']));
        $this->assertSame('Ed25519', $options['trust']['algorithm']);
        $this->assertSame(self::INSTALLATION, $options['trust']['core_instance_id']);
        $this->assertSame(array_keys(RenderGrantCodec::CLIENT_FORMATS), array_column($options['formats'], 'format_id'));
        foreach ($options['formats'] as $format) {
            $rule = RenderGrantCodec::CLIENT_FORMATS[$format['format_id']];
            $this->assertSame($rule['min'], $format['min_version']); $this->assertSame($rule['before'], $format['before_version']);
            $this->assertSame(RenderGrantCodec::profiles($format['format_id']), $format['credential_profiles']);
        }
        $this->assertSame([(string) $subject->id], array_column($options['subjects'], 'access_subject_id'));
        $this->assertSame('https://provider.invalid/subscription-render', $options['subjects'][0]['endpoint']);
        $wire = json_encode($options, JSON_THROW_ON_ERROR);
        foreach (['credential_ref', 'provider_subject_ref', 'private_seed', 'private_key', 'uuid', 'config_digest', 'cors_enabled'] as $field) {
            $this->assertStringNotContainsString('"'.$field.'"', $wire);
        }
        $this->assertStringNotContainsString($subject->credential_ref, $wire);
        $this->assertStringNotContainsString(config('native_node_authorization.private_seed'), $wire);
    }

    public function test_valid_session_without_entitlement_gets_public_discovery_but_no_subjects(): void
    {
        [, $owner] = app(NativeIdentityService::class)->create('browser-empty@example.test', 'runtime-password');
        $this->authenticateActor($owner);
        $this->duringMetadata = static function (): void { throw new \RuntimeException('DENIED_DISCOVERY_MUST_NOT_CALL_METADATA'); };
        $options = $this->delivery->browserOptions($owner->id);
        $this->assertFalse($options['authorized']); $this->assertSame([], $options['subjects']);
        $this->assertCount(6, $options['formats']); $this->assertCount(6, $options['trust']);
        $this->assertStringStartsWith('ENTITLEMENT_', $options['reason']);
    }

    public function test_only_currently_bound_active_subjects_are_discoverable(): void
    {
        [$owner, $subject] = $this->binding(); $this->authenticateActor($owner);
        DB::table('tx_access_subject')->where('id', $subject->id)->update([
            'bound_subscription_version' => (int) $subject->bound_subscription_version + 1]);
        $options = $this->delivery->browserOptions($owner->id);
        $this->assertTrue($options['authorized']); $this->assertSame([], $options['subjects']);
        $this->assertSame('NATIVE_SUBSCRIPTION_DELIVERY_SUBJECTS_UNAVAILABLE', $options['reason']);
    }

    public function test_banned_or_revoked_session_is_rejected_before_metadata(): void
    {
        foreach (['banned', 'revoked'] as $case) {
            [$owner, $subject] = $this->binding(); $this->authenticateActor($owner); $calls = 0;
            $this->duringMetadata = function () use (&$calls): void { $calls++; };
            if ($case === 'banned') DB::table('tx_user')->where('id', $owner->id)->update(['banned' => true]);
            else $this->sessionToken->delete();
            $this->sessionRejects($case, fn () => $this->delivery->browserAuthorize($owner->id, $this->input($subject)));
            $this->assertSame(0, $calls);
        }
    }

    public function test_ban_or_session_revocation_during_metadata_prevents_final_signature(): void
    {
        foreach (['banned', 'revoked'] as $case) {
            [$owner, $subject] = $this->binding(); $this->authenticateActor($owner); $calls = 0; $tokenId = $this->sessionToken->id;
            $this->duringMetadata = function () use ($case, $owner, $tokenId, &$calls): void {
                $this->assertSame(0, DB::transactionLevel()); $calls++;
                if ($case === 'banned') DB::table('tx_user')->where('id', $owner->id)->update(['banned' => true]);
                else DB::table('personal_access_tokens')->where('id', $tokenId)->delete();
            };
            $this->sessionRejects($case, fn () => $this->delivery->browserAuthorize($owner->id, $this->input($subject)));
            $this->assertSame(1, $calls);
        }
    }

    public function test_foreign_subject_and_implicit_or_unsupported_format_versions_never_contact_provider(): void
    {
        [$owner, $subject] = $this->binding();
        [, $other] = app(NativeIdentityService::class)->create('browser-other@example.test', 'runtime-password');
        $this->authenticateActor($other); $calls = 0;
        $this->duringMetadata = function () use (&$calls): void { $calls++; };
        $this->domainRejects('NATIVE_SUBSCRIPTION_DELIVERY_SUBJECT_MISSING', fn () => $this->delivery->browserAuthorize($other->id, $this->input($subject)));
        $this->authenticateActor($owner);
        $missingVersion = $this->input($subject); unset($missingVersion['client_version']);
        $nativeBundle = $this->input($subject); $nativeBundle['format_id'] = RenderGrantCodec::FORMAT;
        $oldVersion = $this->input($subject); $oldVersion['client_version'] = '1.17.9';
        $futureVersion = $this->input($subject); $futureVersion['client_version'] = '2.0.0';
        foreach ([$missingVersion, $nativeBundle, $oldVersion, $futureVersion] as $input) {
            $this->domainRejects('NATIVE_SUBSCRIPTION_DELIVERY_INPUT_INVALID', fn () => $this->delivery->browserAuthorize($owner->id, $input));
        }
        $this->assertSame(0, $calls);
    }

    public function test_explicit_client_formats_produce_short_lived_recipient_bound_grants_without_material(): void
    {
        [$owner, $subject] = $this->binding(); $this->authenticateActor($owner);
        foreach (RenderGrantCodec::CLIENT_FORMATS as $format => $rule) {
            if (!in_array($subject->credential_profile_id, RenderGrantCodec::profiles($format), true)) continue;
            $input = $this->input($subject); $input['format_id'] = $format; $input['client_version'] = $rule['min'];
            $result = $this->delivery->browserAuthorize($owner->id, $input);
            $this->assertArrayNotHasKey('authorization', $result); $this->assertArrayHasKey('grant', $result);
            $raw = base64_decode(strtr($result['grant']['payload'], '-_', '+/'), true);
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame($input['recipient_public_key'], $payload['recipient_public_key']);
            $this->assertSame($format, $payload['format_id']); $this->assertSame($rule['min'], $payload['client_version']);
            $this->assertSame((string) $owner->id, $payload['user_id']);
            $this->assertGreaterThan($payload['issued_at'], $payload['expires_at']);
            $this->assertLessThanOrEqual(30, $payload['expires_at'] - $payload['issued_at']);
            $this->assertArrayNotHasKey('uuid', $payload); $this->assertArrayNotHasKey('private_key', $payload);
            $trust = $this->delivery->browserOptions($owner->id)['trust'];
            $this->assertTrue(sodium_crypto_sign_verify_detached(
                base64_decode(strtr($result['grant']['signature'], '-_', '+/'), true), $raw,
                base64_decode(strtr($trust['public_key'], '-_', '+/'), true)));
        }
    }

    public function test_browser_owner_entries_refuse_outer_transactions_without_consuming_them(): void
    {
        [$owner, $subject] = $this->binding(); $this->authenticateActor($owner);
        DB::transaction(function () use ($owner, $subject): void {
            $level = DB::transactionLevel();
            $this->domainRejects('NATIVE_SUBSCRIPTION_DELIVERY_INPUT_INVALID', fn () => $this->delivery->browserOptions($owner->id));
            $this->domainRejects('NATIVE_SUBSCRIPTION_DELIVERY_INPUT_INVALID', fn () => $this->delivery->browserAuthorize($owner->id, $this->input($subject)));
            $this->assertSame($level, DB::transactionLevel());
        });
    }

    public function test_legacy_http_delivery_rechecks_real_bearer_session_after_metadata(): void
    {
        foreach (['banned', 'revoked'] as $case) {
            [$owner, $subject] = $this->binding();
            $token = $owner->createToken('legacy-http-delivery', ['*']); $tokenId = $token->accessToken->id; $calls = 0;
            $this->duringMetadata = function () use ($case, $owner, $tokenId, &$calls): void {
                $this->assertSame(0, DB::transactionLevel()); $calls++;
                if ($case === 'banned') DB::table('tx_user')->where('id', $owner->id)->update(['banned' => true]);
                else DB::table('personal_access_tokens')->where('id', $tokenId)->delete();
            };
            Auth::forgetGuards();
            $input = $this->input($subject); unset($input['format_id'], $input['client_version']);
            $this->postJson('/txapi/me/subscription-deliveries', $input,
                ['Authorization' => 'Bearer '.$token->plainTextToken])
                ->assertStatus($case === 'banned' ? 403 : 401)->assertJsonMissingPath('data.authorization');
            $this->assertSame(1, $calls);
        }
    }

    public function test_legacy_http_delivery_retains_native_bundle_default_and_original_response_shape(): void
    {
        [$owner, $subject] = $this->binding();
        $token = $owner->createToken('legacy-http-compatible', ['*']); Auth::forgetGuards();
        $input = $this->input($subject); unset($input['format_id'], $input['client_version']);
        $response = $this->postJson('/txapi/me/subscription-deliveries', $input,
            ['Authorization' => 'Bearer '.$token->plainTextToken])->assertOk()
            ->assertJsonPath('data.format_id', RenderGrantCodec::FORMAT)->assertJsonStructure(['data' => [
                'endpoint', 'format_id', 'expires_at', 'proof_scheme', 'authorization' => ['payload', 'signature']]])
            ->assertJsonMissingPath('data.grant');
        $payload = json_decode(base64_decode(strtr($response->json('data.authorization.payload'), '-_', '+/'), true), true, 32, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('client_version', $payload);
        $this->assertSame($input['recipient_public_key'], $payload['recipient_public_key']);
    }

    private function binding(): array
    {
        [$owner, $subscription] = $this->subscribe();
        app(NativeCredentialSynchronization::class)->recover(100);
        $subject = DB::table('tx_access_subject')->where('subscription_id', $subscription['subscription_id'])->where('status', 'active')->first();
        $this->assertNotNull($subject);
        return [$owner, $subject];
    }

    private function authenticateActor(User $owner): void
    {
        $token = $owner->createToken('browser-delivery-owner', ['*']);
        $this->sessionToken = $token->accessToken;
        Auth::forgetGuards(); Auth::guard('sanctum')->setUser($owner->withAccessToken($token->accessToken));
    }

    private function input(object $subject): array
    {
        return ['access_subject_id' => (string) $subject->id,
            'recipient_public_key' => RenderGrantCodec::base64url(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
            'format_id' => 'clash-meta/v1', 'client_version' => '1.18.0'];
    }

    private function sessionRejects(string $case, \Closure $action): void
    {
        try { $action(); $this->fail('Expected current session rejection'); }
        catch (AuthenticationException) { $this->assertSame('revoked', $case); }
        catch (HttpExceptionInterface $error) { $this->assertSame('banned', $case); $this->assertSame(403, $error->getStatusCode()); }
    }

    private function domainRejects(string $code, \Closure $action): void
    {
        try { $action(); $this->fail('Expected '.$code); }
        catch (DomainException $error) { $this->assertSame($code, $error->getMessage()); }
    }
}
