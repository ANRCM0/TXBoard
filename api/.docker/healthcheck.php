<?php

$host = '127.0.0.1';
// Healthchecks run in a fresh docker exec/healthcheck process and do not see
// variables exported by entrypoint after container creation. Use a dedicated
// Docker-level override and otherwise target the fixed internal Caddy-mode port.
$port = (int) (getenv('TXBOARD_HEALTHCHECK_PORT') ?: 7002);
$errno = 0;
$errstr = '';

$socket = @fsockopen($host, $port, $errno, $errstr, 1.0);
if (!$socket) {
    exit(1);
}

stream_set_timeout($socket, 2);
fwrite(
    $socket,
    "GET /api/health HTTP/1.1\r\n"
    . "Host: localhost\r\n"
    . "Connection: close\r\n\r\n"
);

$statusLine = fgets($socket);
fclose($socket);

if (!is_string($statusLine) || !preg_match('/^HTTP\/\d(?:\.\d)?\s+200\b/', $statusLine)) {
    exit(1);
}

$enableMcp = filter_var(getenv('ENABLE_MCP') ?: 'false', FILTER_VALIDATE_BOOL);
if ($enableMcp) {
    $mcpPort = (int) (getenv('MCP_PORT') ?: 3000);
    $mcpSocket = @fsockopen('127.0.0.1', $mcpPort, $errno, $errstr, 1.0);
    if (!$mcpSocket) {
        exit(1);
    }

    stream_set_timeout($mcpSocket, 2);
    fwrite(
        $mcpSocket,
        "GET /healthz HTTP/1.1\r\n"
        . "Host: 127.0.0.1\r\n"
        . "Connection: close\r\n\r\n"
    );

    $mcpStatusLine = fgets($mcpSocket);
    fclose($mcpSocket);

    if (!is_string($mcpStatusLine) || !preg_match('/^HTTP\/\d(?:\.\d)?\s+200\b/', $mcpStatusLine)) {
        exit(1);
    }
}

exit(0);
