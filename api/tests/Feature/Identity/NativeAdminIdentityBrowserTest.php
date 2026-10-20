<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\NativeRuntimeTestCase;

final class NativeAdminIdentityBrowserTest extends NativeRuntimeTestCase
{
    public function test_real_browser_identity_commands_keep_exact_ids_and_recover_private_receipts_under_current_grants(): void
    {
        $this->activate();
        config(['browser.enabled' => true, 'browser.admin_enabled' => true, 'browser.origin' => 'https://core.example.test', 'browser.rate_store' => 'array']);
        admin_setting(['secure_path' => 'native_identity']);
        [, $actor] = app(NativeIdentityService::class)->create('identity-browser@example.test', 'admin-password');
        $actor->forceFill(['is_admin' => true])->saveOrFail();
        foreach (['identity.accounts.manage', 'identity.accounts.read'] as $permission) DB::table('tx_internal_actor_grant')->insert([
            'user_id' => $actor->id, 'permission' => $permission, 'created_at' => time(),
        ]);
        // A large target ID verifies the entire real browser projection, not only a mock fixture.
        [, $target] = app(NativeIdentityService::class)->create('exact-target@example.test', 'original-login-password');
        DB::table('tx_user')->where('id', $target->id)->update(['id' => 9007199254740993]);
        $target = User::findOrFail(9007199254740993); $target->createToken('old-target-session');
        $headers = ['Authorization' => 'Bearer '.$actor->createToken('browser', ['*'])->plainTextToken,
            'X-Core-Admin-Path' => 'native_identity', 'Origin' => 'https://core.example.test'];
        $invoke = fn (string $operation, array $input, ?string $key = null) => $this->postJson(
            'https://core.example.test/gateway/v1/core/admin/operations/'.$operation,
            ['input' => $input === [] ? (object) [] : $input], $headers + ($key ? ['Idempotency-Key' => $key] : []));
        $detail = $invoke('identity.accounts.get', ['user_id' => '9007199254740993'])->assertOk()->assertJsonPath('data.id', '9007199254740993')
            ->assertJsonPath('data.credential_status', 'provider_managed')->assertJsonMissingPath('data.password');
        $profileKey = (string) Str::uuid();
        $profileInput = ['user_id' => '9007199254740993', 'expected_revision' => $detail->json('data.identity_revision'),
            'email' => 'edited-target@example.test', 'password' => 'private-profile-password'];
        $first = $invoke('identity.accounts.profile.update', $profileInput, $profileKey)->assertOk()
            ->assertJsonPath('data.id', '9007199254740993')->assertJsonPath('data.sessions_revoked', 1);
        $this->assertNotSame(\App\Domains\Billing\OrderIdempotency::requestHash($profileInput),
            DB::table('tx_internal_request')->where('operation', 'identity.accounts.profile.update')->value('input_hash'));
        $invoke('identity.accounts.profile.update', $profileInput, $profileKey)->assertOk()->assertJsonPath('data', $first->json('data'));
        // Remove only the transport receipt: the permanent Identity owner receipt must still replay.
        DB::table('tx_internal_request')->where('operation', 'identity.accounts.profile.update')->delete();
        $invoke('identity.accounts.profile.update', $profileInput, $profileKey)->assertOk()->assertJsonPath('data', $first->json('data'));
        $invoke('identity.accounts.profile.update', $profileInput, (string) Str::uuid())->assertStatus(409);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertTrue(password_verify('private-profile-password', $target->fresh()->getRawOriginal('password')));
        $notesKey = (string) Str::uuid();
        $noteInput = ['user_id' => '9007199254740993', 'expected_revision' => $detail->json('data.metadata_revision'), 'remarks' => 'Account support note'];
        $note = $invoke('identity.accounts.metadata.update', $noteInput, $notesKey)->assertOk()->assertJsonPath('data.remarks', 'Account support note');
        DB::table('tx_internal_request')->where('operation', 'identity.accounts.metadata.update')->delete();
        $invoke('identity.accounts.metadata.update', $noteInput, $notesKey)->assertOk()->assertJsonPath('data', $note->json('data'));
        $invoke('identity.accounts.metadata.update', $noteInput, (string) Str::uuid())->assertStatus(409);
        $invoke('identity.accounts.get', ['user_id' => '9007199254740993'])->assertOk()->assertJsonPath('data.remarks', 'Account support note')->assertJsonPath('data.referral', null);
        $batchKey = (string) Str::uuid();
        $batch = ['accounts' => [['email' => 'browser-batch@example.test', 'password' => 'private-batch-password']]];
        $created = $invoke('identity.accounts.batch-create', $batch, $batchKey)->assertOk()->assertJsonPath('data.created_count', 1);
        $this->assertNotSame(\App\Domains\Billing\OrderIdempotency::requestHash($batch),
            DB::table('tx_internal_request')->where('operation', 'identity.accounts.batch-create')->value('input_hash'));
        $this->assertIsString($created->json('data.items.0.id'));
        DB::table('tx_internal_request')->where('operation', 'identity.accounts.batch-create')->delete();
        $invoke('identity.accounts.batch-create', $batch, $batchKey)->assertOk()->assertJsonPath('data', $created->json('data'));
        $this->assertSame(1, User::byEmail('browser-batch@example.test')->count());
        $invoke('identity.accounts.batch-create', ['accounts' => [['email' => 'changed@example.test', 'password' => 'private-batch-password']]], $batchKey)->assertStatus(409);
        $audit = json_encode(DB::table('tx_internal_audit')->get()->all());
        $receipts = json_encode(DB::table('tx_native_identity_admin_request')->get()->all());
        foreach (['private-profile-password', 'private-batch-password'] as $secret) {
            $this->assertStringNotContainsString($secret, $audit); $this->assertStringNotContainsString($secret, $receipts);
        }
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->delete();
        $invoke('identity.accounts.get', ['user_id' => '9007199254740993'])->assertForbidden();
        $invoke('identity.accounts.profile.update', $profileInput, $profileKey)->assertForbidden();
        $invoke('identity.accounts.batch-create', $batch, $batchKey)->assertForbidden();
        $invoke('identity.accounts.metadata.update', $noteInput, $notesKey)->assertForbidden();
    }
}
