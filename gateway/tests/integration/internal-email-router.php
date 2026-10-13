<?php

// Dedicated local test router. The real HTTP kernel, mailer and native issuer run unchanged.
define('LARAVEL_START', microtime(true));
$api = realpath(getenv('INTERNAL_CORE_API') ?: '');
$directory = realpath(getenv('INTERNAL_FIXTURE_DIR') ?: '');
if (!$api || !$directory || dirname($directory) !== '/tmp'
    || !str_starts_with(basename($directory), 'txboard-internal-user-gateway.')
    || (fileperms($directory) & 0777) !== 0700) {
    throw new RuntimeException('Invalid isolated email witness directory');
}
require $api.'/vendor/autoload.php';
$app = require $api.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
// Match HttpKernel's normal request binding before bootstrapping providers.
$app->instance('request', $request);
Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
$kernel->bootstrap();
if (!$app->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== (getenv('INTERNAL_REGISTRATION_SHADOW_VERIFY') === 'true'
        ? 'txboard_registration_transport_test' : 'txboard_internal_test')) {
    throw new RuntimeException('Exclusive disposable email database required');
}
$watched = in_array($request->getPathInfo(), [
    '/txapi/internal/v1/operations/auth.email-code.send', '/txapi/auth/email-code',
], true);
$messageCount = 0;
$bodyMatches = true;
$cacheMatches = true;
$messages = [];
if ($watched) {
    $app['events']->listen(Illuminate\Mail\Events\MessageSent::class,
        function ($event) use (&$messageCount, &$bodyMatches, &$messages): void {
            // The real sender puts the code in Cache after the event; retain only
            // request-local memory until kernel.handle returns. No secret persists.
            $code = $event->data['code'] ?? null;
            $email = $event->message->getTo()[0]->getAddress() ?? '';
            $messageCount++;
            $bodyMatches = $bodyMatches && is_int($code) && preg_match('/\A[0-9]{6}\z/', (string) $code)
                && str_contains($event->message->getHtmlBody() ?? '', (string) $code);
            $messages[] = ['email' => $email, 'code' => $code];
        });
}
$response = $kernel->handle($request);
if ($watched && $messageCount > 0) {
    $bodyMatches = $bodyMatches && $messageCount === 1;
    $cacheMatches = $cacheMatches && $messageCount === 1;
    foreach ($messages as $message) {
        // Laravel RedisStore returns un-serialized numeric values as strings.
        $cached = Illuminate\Support\Facades\Cache::get(
            App\Utils\CacheKey::get('EMAIL_VERIFY_CODE', $message['email']));
        $cacheMatches = $cacheMatches && (is_int($cached) || is_string($cached))
            && preg_match('/\A[0-9]{6}\z/', (string) $cached)
            && (string) $cached === (string) $message['code'];
    }
    $transportIsArray = false;
    if ($messageCount > 0) {
        $mailer = Illuminate\Support\Facades\Mail::mailer();
        $transport = $mailer->getSymfonyTransport();
        $transportIsArray = $mailer::class === Illuminate\Mail\Mailer::class
            && $transport::class === Illuminate\Mail\Transport\ArrayTransport::class
            && $transport->messages()->count() === $messageCount;
    }
    $witness = ['local_message_count' => $messageCount, 'body_code_matches' => (bool) $bodyMatches,
        'cache_code_matches' => (bool) $cacheMatches, 'actual_array_transport' => $transportIsArray];
    $file = $directory.'/email-witness-'.bin2hex(random_bytes(16)).'.json';
    $stream = fopen($file, 'x');
    if (!$stream || !chmod($file, 0600) || fwrite($stream, json_encode($witness, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Local email witness write failed');
    }
    fclose($stream);
    unset($messages, $message, $code, $email, $cached);
}
$response->send();
$kernel->terminate($request, $response);
