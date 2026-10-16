<?php

try {
    $app = require __DIR__.'/bootstrap.php';
    providerNodeAdmitFixture();
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $request = Illuminate\Http\Request::capture();
    $response = $kernel->handle($request);
    $response->send();
    $kernel->terminate($request, $response);
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"error":{"code":"PROVIDER_NODE_FIXTURE_FAILED"}}';
}
