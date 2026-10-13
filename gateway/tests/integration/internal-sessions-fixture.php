<?php

// Fixed console-only synthetic fixture, never an application HTTP operation.
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
switch ($argv[2] ?? '') {
    case 'bound-101':
        if ($user->tokens()->count() !== 3) throw new RuntimeException('Expected exact session fixture');
        for ($i = 0; $i < 98; $i++) $user->createToken('bound-fixture');
        echo '{"ok":true}'; break;
    case 'bound-100':
        if ($user->tokens()->count() !== 101) throw new RuntimeException('Expected bound fixture');
        $user->tokens()->where('name', 'bound-fixture')->orderByDesc('id')->firstOrFail()->delete();
        echo '{"ok":true}'; break;
    case 'bound-clean':
        $user->tokens()->where('name', 'bound-fixture')->delete(); echo '{"ok":true}'; break;
    default: throw new RuntimeException('Unknown fixture action');
}
