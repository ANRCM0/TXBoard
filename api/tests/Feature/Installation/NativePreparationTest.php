<?php

namespace Tests\Feature\Installation;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\NativePreparation;
use App\Domains\Network\Internal\InitialNetwork;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class NativePreparationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'native_preparation_testing', 'core_installation.mode' => 'native',
            'core_installation.worker_epoch' => '1', 'database.connections.native_preparation_testing' =>
                array_merge(config('database.connections.sqlite'), ['database' => ':memory:', 'foreign_key_constraints' => true])]);
        DB::purge('native_preparation_testing');
        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_exact_private_input_prepares_owner_data_once_without_legacy_identity_or_secret_output(): void
    {
        $input = $this->input();
        $first = app(NativePreparation::class)->prepare($input);
        $this->assertSame($first, app(NativePreparation::class)->prepare($input));
        foreach (['tx_user', 'tx_server', 'tx_server_group', 'tx_plan', 'tx_plan_revision'] as $table) $this->assertSame(1, DB::table($table)->count());
        $this->assertSame(4, DB::table('tx_native_preparation_step')->count());
        $user = DB::table('tx_user')->first();
        $this->assertTrue(password_verify($input['identity']['password'], $user->password));
        $this->assertSame('', $user->uuid);
        $this->assertSame('', $user->token);
        $this->assertNull($user->plan_id);
        $this->assertSame(0, (int) $user->balance);
        $this->assertStringNotContainsString($input['identity']['password'], json_encode($first));
        $this->assertStringNotContainsString($input['identity']['password'], DB::table('tx_native_preparation_step')->get()->toJson());
        $this->assertSame('initializing', app(InstallationAuthority::class)->inspect()['phase']);
        $this->assertSame(0, DB::table('tx_plugins')->count());
    }

    public function test_interrupted_cross_owner_preparation_resumes_same_manifest_and_rejects_changed_secret(): void
    {
        $input = $this->input();
        $network = app(InitialNetwork::class);
        $this->app->instance(InitialNetwork::class, new class implements InitialNetwork {
            public function prepare(string $key, array $input): array { throw new \RuntimeException('simulated process loss'); }
        });
        try { app(NativePreparation::class)->prepare($input); $this->fail('Expected interruption'); }
        catch (\RuntimeException $error) { $this->assertSame('simulated process loss', $error->getMessage()); }
        $this->assertSame(1, DB::table('tx_user')->count());
        $this->assertSame(0, DB::table('tx_plan')->count());
        $changed = $input;
        $changed['identity']['password'] .= '-changed';
        try { app(NativePreparation::class)->prepare($changed); $this->fail('Expected original-input conflict'); }
        catch (\RuntimeException $error) { $this->assertSame('NATIVE_PREPARATION_CONFLICT', $error->getMessage()); }
        $this->app->instance(InitialNetwork::class, $network);
        app(NativePreparation::class)->prepare($input);
        $this->assertSame(4, DB::table('tx_native_preparation_step')->count());
        $this->assertSame(1, DB::table('tx_user')->count());
        $this->assertSame(1, DB::table('tx_plan_revision')->count());
    }

    public function test_invalid_cross_domain_references_fail_before_creating_installation_identity(): void
    {
        $input = $this->input();
        $input['catalog']['plans'][0]['group_id'] = 99;
        try { app(NativePreparation::class)->prepare($input); $this->fail('Expected invalid input'); }
        catch (\RuntimeException $error) { $this->assertSame('NATIVE_PREPARATION_INPUT_INVALID', $error->getMessage()); }
        $this->assertFalse(app(InstallationAuthority::class)->inspect()['initialized']);
        $this->assertSame(0, DB::table('tx_user')->count());
    }

    private function input(): array
    {
        return json_decode(file_get_contents(base_path('native-preparation.example.json')), true, 32, JSON_THROW_ON_ERROR);
    }

    public function test_native_schedule_registration_does_not_require_an_initialized_authority_record(): void
    {
        $authority = \Mockery::mock(InstallationAuthority::class);
        $authority->shouldNotReceive('inspect');
        $this->app->instance(InstallationAuthority::class, $authority);
        $schedule = new \Illuminate\Console\Scheduling\Schedule;
        (new \ReflectionMethod(\App\Console\Kernel::class, 'schedule'))
            ->invoke(app(\Illuminate\Contracts\Console\Kernel::class), $schedule);
        $this->assertCount(4, $schedule->events());
        $this->assertSame(0, DB::table('tx_authority_writer_fence')->count());
    }
}
