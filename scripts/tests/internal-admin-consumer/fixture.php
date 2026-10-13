<?php

require getenv('INTERNAL_CORE_API').'/vendor/autoload.php';
$app = require getenv('INTERNAL_CORE_API').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'txboard_admin_consumer_test') {
    throw new RuntimeException('Exclusive synthetic txboard_admin_consumer_test required');
}
$mode = $argv[1] ?? 'seed';
if ($mode === 'revoke-all') {
    Laravel\Sanctum\PersonalAccessToken::query()->delete(); exit;
}
if ($mode === 'state') {
    echo json_encode(['token_count' => Laravel\Sanctum\PersonalAccessToken::count(),
        'audit_count' => Illuminate\Support\Facades\DB::table('tx_internal_readonly_audits')->count(),
        'database_version' => Illuminate\Support\Facades\DB::selectOne('SELECT VERSION() AS version')->version]);
    exit;
}
if ($mode === 'mutate') {
    $user = App\Models\User::query()->where('email', 'login-admin@example.test')->firstOrFail();
    match ($argv[2] ?? '') {
        'ban' => $user->update(['banned' => true]), 'unban' => $user->update(['banned' => false]),
        'demote' => $user->update(['is_admin' => false]), 'promote' => $user->update(['is_admin' => true]),
        'revoke' => $user->tokens()->delete(),
        'captcha-on' => admin_setting(['captcha_enable' => 1, 'captcha_type' => 'turnstile']),
        'captcha-off' => admin_setting(['captcha_enable' => 0]),
        default => throw new RuntimeException('Unknown fixed mutation'),
    };
    exit;
}
if ($mode === 'audit-fail') {
    Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER admin_consumer_audit_failure BEFORE INSERT ON tx_internal_readonly_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic-private-admin-failure'"); exit;
}
if ($mode === 'audit-restore') {
    Illuminate\Support\Facades\DB::unprepared('DROP TRIGGER admin_consumer_audit_failure'); exit;
}
if ($mode === 'audit-check') {
    $json = Illuminate\Support\Facades\DB::table('tx_internal_readonly_audits')->get()->toJson();
    foreach (['Bearer ', 'fixture-admin-password', 'private-admin-path', '@example.test', 'private-subscription'] as $secret) {
        if (str_contains($json, $secret)) throw new RuntimeException('Audit leaked fixture secret');
    }
    echo 'safe'; exit;
}
if ($mode !== 'seed' || App\Models\User::query()->exists()) throw new RuntimeException('Seed requires empty synthetic tables');
admin_setting(['captcha_enable' => 0, 'password_limit_enable' => true, 'password_limit_count' => 5,
    'password_limit_expire' => 60, 'secure_path' => 'private-admin-path']);
$ids = [];
foreach (['admin','user','staff'] as $role) {
    $user = App\Models\User::create(['email' => 'login-'.$role.'@example.test',
        'password' => Illuminate\Support\Facades\Hash::make('fixture-admin-password'),
        'uuid' => (string) Illuminate\Support\Str::uuid(), 'token' => 'private-subscription-'.$role,
        'is_admin' => $role === 'admin', 'is_staff' => $role === 'staff', 'banned' => false]);
    $ids[$role] = (string) $user->id;
}
App\Models\StatServer::create(['server_id' => 1, 'server_type' => 'txnode', 'u' => '9007199254740993', 'd' => 13,
    'record_type' => 'd', 'record_at' => time() - 1]);
echo json_encode(['ids' => $ids], JSON_THROW_ON_ERROR);
