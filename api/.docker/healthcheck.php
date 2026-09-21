<?php

$host = '127.0.0.1';
$port = (int) (getenv('OCTANE_PORT') ?: 7002);
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

exit(0);
