<?php

namespace App\Domains\CredentialProviderFixture;

use DomainException;

/** Fixed trusted executable, never a general process launcher or OS sandbox. */
final class FixtureProcess
{
    public static function script(): string { return base_path('provider-runtime-fixtures/synthetic-provider.php'); }
    public static function digest(): string
    {
        $hash = @hash_file('sha256', self::script());
        if (!$hash) { throw new DomainException('RUNTIME_UNAVAILABLE'); }
        return $hash;
    }

    public function call(array $request): array
    {
        if (!config('credential_provider_fixture.enabled') || !config('core_shadow.isolated_copy') || !app()->environment('local', 'testing')) {
            throw new DomainException('CAPABILITY_UNAVAILABLE');
        }
        $json = FixtureCodec::json($request);
        $budget = config('credential_provider_fixture.wall_timeout_seconds');
        if (strlen($json) > FixtureCodec::MAX_BYTES || !is_numeric($budget) || $budget <= 0 || $budget > 2) {
            throw new DomainException('PROVIDER_LIMIT_INVALID');
        }
        $deadline = FixtureCodec::time($request['deadline_at'] ?? null);
        if ($deadline <= time() || $deadline > time() + 5) { throw new DomainException('DEADLINE_EXCEEDED'); }
        $root = config('credential_provider_fixture.private_root');
        $root = is_string($root) ? @realpath($root) : false;
        if (!$root || !is_dir($root) || str_contains($root, PATH_SEPARATOR)
            || (PHP_OS_FAMILY !== 'Windows' && ((fileperms($root) & 0077) !== 0))) {
            throw new DomainException('RUNTIME_UNAVAILABLE');
        }
        $fault = app()->environment('testing') ? config('credential_provider_fixture.test_fault') : null;
        if (!in_array($fault, [null, 'after_commit_drop', 'after_commit_sleep', 'oversize', 'malformed', 'wrong_identity'], true)) {
            throw new DomainException('PROVIDER_INPUT_INVALID');
        }
        $argv = [PHP_BINARY, '-n', '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'memory_limit=32M',
            '-d', 'allow_url_fopen=0', '-d', 'open_basedir='.$root.PATH_SEPARATOR.dirname(self::script()),
            '-d', 'disable_functions=exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec,stream_socket_client,stream_socket_server,fsockopen,pfsockopen', self::script()];
        $process = @proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root,
            ['CP_FIXTURE_ROOT' => $root, 'CP_FIXTURE_FAULT' => $fault ?? '', 'LANG' => 'C'], ['bypass_shell' => true]);
        if (!is_resource($process)) { throw new DomainException('RUNTIME_UNAVAILABLE'); }
        $output = ''; $diagnosticBytes = 0; $code = null; $exitCode = null;
        $until = hrtime(true) / 1e9 + min((float) $budget, $deadline - time());
        try {
            $offset = 0;
            stream_set_blocking($pipes[0], false);
            while ($offset < strlen($json)) {
                $written = @fwrite($pipes[0], substr($json, $offset));
                if ($written === false || hrtime(true) / 1e9 >= $until) { $code = 'RUNTIME_UNKNOWN'; break; }
                $offset += $written;
                if ($written === 0) { usleep(1000); }
            }
            fclose($pipes[0]); unset($pipes[0]);
            stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
            while ($code === null) {
                $chunk = @fread($pipes[1], FixtureCodec::MAX_BYTES + 1);
                if (is_string($chunk)) { $output .= $chunk; }
                $diagnostic = @fread($pipes[2], FixtureCodec::MAX_BYTES + 1);
                $diagnosticBytes += is_string($diagnostic) ? strlen($diagnostic) : 0;
                if (strlen($output) > FixtureCodec::MAX_BYTES || $diagnosticBytes > FixtureCodec::MAX_BYTES) {
                    $code = 'PROVIDER_RESPONSE_LIMIT'; break;
                }
                $status = proc_get_status($process);
                if (!$status['running'] && feof($pipes[1]) && feof($pipes[2])) {
                    if ($status['exitcode'] >= 0) { $exitCode = $status['exitcode']; }
                    break;
                }
                if (!$status['running'] && $status['exitcode'] >= 0) { $exitCode = $status['exitcode']; }
                if (hrtime(true) / 1e9 >= $until) { $code = 'RUNTIME_UNKNOWN'; break; }
                usleep(1000);
            }
        } finally {
            if (proc_get_status($process)['running']) {
                @proc_terminate($process);
                usleep(10000);
                if (proc_get_status($process)['running']) { @proc_terminate($process, 9); }
            }
            foreach ($pipes as $pipe) { fclose($pipe); }
            $closed = proc_close($process);
            if ($exitCode === null || $exitCode < 0) { $exitCode = $closed; }
        }
        if ($code !== null) { throw new DomainException($code); }
        if ($exitCode !== 0 || $output === '') { throw new DomainException('RUNTIME_UNKNOWN'); }
        try { $response = json_decode($output, true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new DomainException('PROVIDER_INVALID_RESPONSE'); }
        if (!is_array($response) || array_is_list($response)) { throw new DomainException('PROVIDER_INVALID_RESPONSE'); }
        return $response;
    }
}
