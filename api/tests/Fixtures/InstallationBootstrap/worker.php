<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $result = app(App\Domains\Installation\InstallationAuthority::class)->initialize(
        '550e8400-e29b-41d4-a716-446655440000', 'native', 'parallel-installation');
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $error) {
    echo json_encode(['error_code' => $error instanceof App\Domains\Installation\InstallationAuthorityException
        ? $error->getMessage() : 'INSTALLATION_FIXTURE_FAILED']);
    exit(1);
}
