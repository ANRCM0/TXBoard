<?php

// Not routed. Only this disposable synthetic database may be seeded or mutated.
require getenv('INTERNAL_CORE_API').'/vendor/autoload.php';
$app = require getenv('INTERNAL_CORE_API').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || config('database.default') !== 'mysql'
    || !in_array(config('database.connections.mysql.database'), ['txboard_admin_dashboard_test', 'txboard_admin_dashboard_retry_test', 'txboard_admin_dashboard_final_test'], true)) {
    throw new RuntimeException('Exclusive disposable Admin dashboard fixture database required');
}
$mode = $argv[1] ?? 'seed';
if ($mode === 'mutate') {
    $actor = App\Models\User::query()->where('email', 'dashboard-admin@example.test')->firstOrFail();
    match ($argv[2] ?? '') {
        'ban' => $actor->update(['banned' => true]),
        'unban' => $actor->update(['banned' => false]),
        'demote' => $actor->update(['is_admin' => false]),
        'promote' => $actor->update(['is_admin' => true]),
        'revoke' => $actor->tokens()->delete(),
        'rotate-path' => admin_setting(['secure_path' => 'rotated-dashboard-fixture']),
        default => throw new RuntimeException('Unknown fixed fixture mutation'),
    };
    exit;
}
if ($mode === 'source-check') {
    echo hash('sha256', App\Models\User::query()->orderBy('id')->get()->toJson().App\Models\StatServer::query()->get()->toJson());
    exit;
}
if ($mode === 'audit-fail') {
    Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER dashboard_audit_failure BEFORE INSERT ON tx_internal_readonly_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic-private-audit-failure'");
    exit;
}
if ($mode === 'audit-restore') {
    Illuminate\Support\Facades\DB::unprepared('DROP TRIGGER dashboard_audit_failure');
    exit;
}
if ($mode === 'audit-check') {
    $rows = Illuminate\Support\Facades\DB::table('tx_internal_readonly_audits')->get()->toJson();
    foreach (['Bearer ', 'private-dashboard-token', 'PRIVATE KEY', 'dashboard-fixture-path', 'synthetic-private'] as $secret) {
        if (str_contains($rows, $secret)) throw new RuntimeException('Audit leaked fixture secret');
    }
    echo json_encode(['audit_rows' => Illuminate\Support\Facades\DB::table('tx_internal_readonly_audits')->count(),
        'nonce_rows' => Illuminate\Support\Facades\DB::table('tx_internal_readonly_nonces')->count(),
        'database_version' => Illuminate\Support\Facades\DB::selectOne('SELECT VERSION() AS version')->version]);
    exit;
}
if ($mode !== 'seed' || App\Models\User::query()->exists()) throw new RuntimeException('Seed requires empty synthetic tables');
admin_setting(['secure_path' => 'dashboard-fixture-path']);
$credentials = [];
$ids = [];
foreach (['admin', 'user', 'staff'] as $role) {
    $actor = App\Models\User::create(['email' => 'dashboard-'.$role.'@example.test',
        'password' => Illuminate\Support\Facades\Hash::make('fixture-password-only'),
        'uuid' => (string) Illuminate\Support\Str::uuid(), 'token' => 'private-dashboard-token-'.$role,
        'is_admin' => $role === 'admin', 'is_staff' => $role === 'staff', 'banned' => false]);
    $ids[$role] = (string) $actor->id;
    $credentials[$role] = 'Bearer '.$actor->createToken('dashboard-fixture-'.$role, ['*'], now()->addHour())->plainTextToken;
    if ($role === 'admin') {
        $credentials['expired'] = 'Bearer '.$actor->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        $credentials['agent'] = 'Bearer '.$actor->createToken('agent:retired-fixture', ['*'])->plainTextToken;
        $credentials['renamed_agent'] = 'Bearer '.$actor->createToken('renamed-retired', ['agent:nodes:read'])->plainTextToken;
    }
}
App\Models\StatServer::create(['server_id' => 1, 'server_type' => 'txnode', 'u' => '9007199254740993', 'd' => 13,
    'record_type' => 'd', 'record_at' => time() - 1]);
echo json_encode(['credentials' => $credentials, 'ids' => $ids, 'admin_path' => 'dashboard-fixture-path'], JSON_THROW_ON_ERROR);
