<?php

// Console-only synthetic fixture. Never an application HTTP route.
$api = realpath($argv[1] ?? '');
if (!$api || !is_file($api.'/vendor/autoload.php')) throw new RuntimeException('Pass isolated Core api');
require $api.'/vendor/autoload.php';
$app = require $api.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'txboard_internal_test') {
    throw new RuntimeException('Exclusive disposable txboard_internal_test required');
}
$user = App\Models\User::where('email', 'internal-user@example.test')->firstOrFail();
$other = App\Models\User::where('email', 'internal-other@example.test')->firstOrFail();
$db = Illuminate\Support\Facades\DB::class;
switch ($argv[2] ?? '') {
    case 'remaining':
        echo json_encode(['credential'=>'Bearer '.$user->createToken('remaining-device')->plainTextToken]); break;
    case 'state':
        echo json_encode(['user_sessions'=>$user->tokens()->count(),'other_sessions'=>$other->tokens()->count(),
            'users'=>App\Models\User::count(),'orders'=>App\Models\Order::count()]); break;
    case 'delete-reject':
        $db::unprepared("CREATE TRIGGER reject_session_delete BEFORE DELETE ON personal_access_tokens FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture-private-session-sql-error'");
        echo '{"ok":true}'; break;
    case 'delete-allow':
        $db::unprepared('DROP TRIGGER reject_session_delete'); echo '{"ok":true}'; break;
    case 'audit-reject':
        $db::unprepared("CREATE TRIGGER reject_session_audit BEFORE INSERT ON tx_internal_readonly_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture-private-session-sql-error'");
        echo '{"ok":true}'; break;
    case 'audit-allow':
        $db::unprepared('DROP TRIGGER reject_session_audit'); echo '{"ok":true}'; break;
    default: throw new RuntimeException('Unknown fixture action');
}
