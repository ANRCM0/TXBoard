<?php
// Isolated CI TLS termination adapter. The PHP development server has no TLS.
// Only the loopback test proxy with the random per-process secret can mark its
// verified connection secure. Production middleware remains unchanged.
$root = getenv('TXBOARD_CORE_TEST_ROOT');
if (!$root || !is_file($root.'/.gateway-integration-snapshot') || !getenv('CORE_USER_PROXY_NONCE')) {
    http_response_code(503); exit;
}
if (str_starts_with($_SERVER['REQUEST_URI'], '/txapi/internal/')) {
    if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1'
        || !hash_equals(getenv('CORE_USER_PROXY_NONCE'), $_SERVER['HTTP_X_CI_TLS_NONCE'] ?? '')) {
        http_response_code(403); exit;
    }
    $_SERVER['HTTPS'] = 'on';
}
require $root.'/public/index.php';
