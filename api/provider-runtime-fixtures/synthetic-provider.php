<?php

// Standalone trusted fixture. No Laravel, Core DB, plugin code or production credential format.
error_reporting(0);
ini_set('display_errors', '0'); ini_set('log_errors', '0');
umask(0077);

try {
    $raw = stream_get_contents(STDIN, 4097);
    if (!is_string($raw) || strlen($raw) > 4096) { exit(2); }
    $request = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($request) || ($request['contract_version'] ?? '') !== 'credential-provider/local-fixture/1'
        || !in_array($request['operation'] ?? '', ['issue', 'query', 'revoke'], true)
        || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $request['provider_id'] ?? '')
        || !preg_match('/^op_[a-f0-9]{32}$/D', $request['operation_id'] ?? '')
        || !is_int($request['deadline_at'] ?? null) || $request['deadline_at'] <= time()) { exit(2); }
    $root = getenv('CP_FIXTURE_ROOT');
    $directory = $root.'/'.$request['provider_id'];
    if (!is_dir($directory) && !mkdir($directory, 0700)) { exit(2); }
    $lock = fopen($directory.'/state.lock', 'c');
    if (!$lock) { exit(2); }
    $until = hrtime(true) / 1e9 + min(1.5, $request['deadline_at'] - time());
    while (!flock($lock, LOCK_EX | LOCK_NB)) {
        if (hrtime(true) / 1e9 >= $until) { exit(2); }
        usleep(1000);
    }
    $path = $directory.'/state.json';
    $state = ['provider_id' => $request['provider_id'], 'epoch' => $request['provider_instance_epoch'], 'operations' => [], 'subjects' => []];
    if (is_file($path)) {
        if (filesize($path) > 1048576) { exit(2); }
        $state = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    }
    if ($state['provider_id'] !== $request['provider_id'] || $state['epoch'] !== $request['provider_instance_epoch']) { exit(2); }
    $state['tombstones'] ??= [];
    $id = $request['operation_id'];
    $existing = $state['operations'][$id] ?? null;
    $status = 'pending'; $result = null; $error = null; $changed = false;
    if ($existing !== null) {
        if ($existing['input_hash'] !== $request['input_hash'] || $existing['subject_id'] !== $request['input']['access_subject_id']) {
            $status = 'rejected'; $error = 'IDEMPOTENCY_CONFLICT';
        } else { $status = 'succeeded'; $result = $existing['receipt']; }
    } elseif ($request['operation'] === 'query') {
        $error = 'OPERATION_UNKNOWN';
    } elseif ($request['operation'] === 'revoke') {
        $input = $request['input'];
        if (($input['revocation_fact_domain'] ?? '') !== 'provider-subject/v1'
            || !preg_match('/^[1-9][0-9]{0,18}$/D', $input['revocation_fact_version'] ?? '')
            || strlen($input['revocation_fact_version']) === 19 && strcmp($input['revocation_fact_version'], '9223372036854775807') > 0
            || !is_int($input['effective_at'] ?? null) || $input['effective_at'] < 0 || $input['effective_at'] > time()
            || !in_array($input['reason_code'] ?? '', ['operator_revoke', 'provider_disable'], true)) { exit(2); }
        $subject = $input['access_subject_id'];
        $tombstone = $state['tombstones'][$subject] ?? null;
        $known = $input['known_issue'] ?? null;
        $issueId = $state['subjects'][$subject] ?? null;
        if ($known !== null && (!is_array($known) || count($known) !== 2
            || $issueId !== ($known['operation_ref'] ?? null)
            || ($state['operations'][$issueId]['receipt']['credential_ref'] ?? null) !== ($known['credential_ref'] ?? null))) {
            // Losing previously acknowledged private history is not proof of a clean credential.
            $error = 'OPERATION_UNKNOWN';
        } elseif ($tombstone !== null && (int) $tombstone['fact_version'] >= (int) $input['revocation_fact_version']) {
            $status = 'rejected'; $error = 'REVOCATION_VERSION_CONFLICT';
        } elseif (count($state['operations']) >= 256 || $tombstone === null && count($state['tombstones']) >= 128) {
            $status = 'rejected'; $error = 'PROVIDER_PRIVATE_LIMIT';
        } else {
            $removed = $issueId !== null && isset($state['operations'][$issueId]['fixture_secret']);
            $at = time();
            if ($issueId !== null) {
                unset($state['operations'][$issueId]['fixture_secret']);
                $state['operations'][$issueId]['credential_status'] = 'revoked';
                $state['operations'][$issueId]['revoked_at'] = $at;
                $state['operations'][$issueId]['revocation_fact_version'] = $input['revocation_fact_version'];
            }
            $result = ['access_subject_id' => $subject, 'accepted_revocation_fact_version' => $input['revocation_fact_version'],
                'provider_revoked_at' => $at, 'affected_subject_count' => $issueId === null ? '0' : '1',
                'removed_secret_count' => $removed ? '1' : '0', 'private_cleanup_complete' => true];
            $state['operations'][$id] = ['operation' => 'revoke', 'input_hash' => $request['input_hash'], 'subject_id' => $subject,
                'receipt' => $result, 'provider_pid' => getmypid()];
            $state['tombstones'][$subject] = ['fact_version' => $input['revocation_fact_version'], 'effective_at' => $input['effective_at'],
                'revoked_at' => $at, 'operation_id' => $id];
            $changed = true; $status = 'succeeded';
        }
    } elseif (($request['input']['credential_profile_id'] ?? '') !== 'synthetic-v1') {
        $status = 'rejected'; $error = 'UNSUPPORTED_PROFILE';
    } elseif (isset($state['tombstones'][$request['input']['access_subject_id']])) {
        $status = 'rejected'; $error = 'REVOKED';
    } elseif (isset($state['subjects'][$request['input']['access_subject_id']])) {
        $status = 'rejected'; $error = 'SUBJECT_BINDING_CONFLICT';
    } elseif (count($state['subjects']) >= 128 || count($state['operations']) >= 256) {
        $status = 'rejected'; $error = 'PROVIDER_PRIVATE_LIMIT';
    } else {
        $input = $request['input'];
        if (!is_int($input['not_before_at']) || !is_int($input['credential_expires_at'])
            || $input['credential_expires_at'] <= $input['not_before_at'] || $input['credential_expires_at'] <= time()) { exit(2); }
        $secret = bin2hex(random_bytes(32));
        $result = ['credential_ref' => 'cr_'.bin2hex(random_bytes(16)), 'provider_subject_ref' => 'ps_'.bin2hex(random_bytes(16)),
            'credential_generation' => '1', 'issued_at' => time(), 'credential_expires_at' => $input['credential_expires_at']];
        $state['operations'][$id] = ['operation' => 'issue', 'input_hash' => $request['input_hash'], 'subject_id' => $input['access_subject_id'],
            'fixture_secret' => $secret, 'receipt' => $result, 'provider_pid' => getmypid(),
            'core_env_present' => getenv('DB_PASSWORD') !== false || getenv('REDIS_PASSWORD') !== false || getenv('APP_KEY') !== false,
            'core_file_readable' => @file_get_contents(dirname(__DIR__).'/.env') !== false,
            'php_network_functions_enabled' => function_exists('stream_socket_client') || function_exists('fsockopen')];
        $state['subjects'][$input['access_subject_id']] = $id;
        $changed = true; $status = 'succeeded';
    }
    if ($changed) {
        $temporary = tempnam($directory, 'state-');
        chmod($temporary, 0600);
        if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR)) === false || !rename($temporary, $path)) { exit(2); }
    }
    flock($lock, LOCK_UN); fclose($lock);
    $fault = getenv('CP_FIXTURE_FAULT');
    if ($changed && $fault === 'after_commit_drop') { exit(3); }
    if ($changed && $fault === 'after_commit_sleep') { usleep(3000000); }
    if ($fault === 'oversize') { echo str_repeat('x', 4097); exit(0); }
    if ($fault === 'malformed') { echo '{"credential":"blocked-fixture-marker"}'; exit(0); }
    $response = ['contract_version' => $request['contract_version'], 'request_id' => $request['request_id'],
        'operation_id' => $id, 'provider_id' => $request['provider_id'], 'provider_instance_epoch' => $request['provider_instance_epoch'],
        'input_hash' => $request['input_hash'], 'status' => $status, 'result' => $result, 'error_code' => $error];
    if ($fault === 'wrong_identity') { $response['provider_id'] = 'other_provider'; }
    $encoded = json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (strlen($encoded) > 4096) { exit(2); }
    echo $encoded;
} catch (Throwable) {
    // No exception text, secrets, state, raw requests or paths cross the private runtime boundary.
    exit(2);
}
