<?php

return [
    // These must be supplied from trusted deployed release metadata (not a ZIP).
    // Blank values fail closed during v2 installation and activation.
    'txboard_version' => env('THEME_V2_TXBOARD_VERSION', ''),
    'browser_version' => env('THEME_V2_BROWSER_VERSION', ''),
    'sdk_version' => env('THEME_V2_SDK_VERSION', ''),

    // Server-owned presentation-field allowlist. Manifest config.public can
    // only narrow this set; it can never grant public access to a new key.
    'public_fields' => ['theme_color', 'primaryColor', 'background_url', 'siteBanner'],
    // Only deployment-owned, digest-pinned local ZIPs can be selected in the browser.
    'deployment_candidates' => (static function (): array {
        $file = env('THEME_V2_CANDIDATE_POLICY_FILE');
        if ($file === null || $file === '') return [];
        $policy = \App\Core\Host\Configuration\TrustedConfiguration::object($file);
        if (array_keys($policy) !== ['candidates'] || !is_array($policy['candidates']) || !array_is_list($policy['candidates'])
            || count($policy['candidates']) > 32) throw new RuntimeException('THEME_CANDIDATE_POLICY_INVALID');
        $result = [];
        foreach ($policy['candidates'] as $candidate) {
            if (!is_array($candidate) || count($candidate) !== 3 || array_diff(array_keys($candidate), ['id', 'path', 'sha256'])
                || !is_string($candidate['id'] ?? null) || preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $candidate['id']) !== 1
                || isset($result[$candidate['id']]) || !is_string($candidate['path'] ?? null) || strlen($candidate['path']) > 4096
                || !is_string($candidate['sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $candidate['sha256']) !== 1) {
                throw new RuntimeException('THEME_CANDIDATE_POLICY_INVALID');
            }
            $result[$candidate['id']] = ['path' => $candidate['path'], 'sha256' => $candidate['sha256']];
        }
        return $result;
    })(),
];
