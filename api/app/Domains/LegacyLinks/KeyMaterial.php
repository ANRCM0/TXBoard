<?php
namespace App\Domains\LegacyLinks;
use App\Domains\InternalReadOnly\StrictJson;
final class KeyMaterial
{
    private function __construct(private readonly string $key, public readonly string $id, public readonly string $generation) {}
    public static function load(): self
    {
        Canonical::require(config('legacy_subscription_links.enabled') === true
            && config('core_shadow.isolated_copy') === true && app()->environment(['local','testing']), 'LINK_ISOLATED_REQUIRED');
        $settings = config('legacy_subscription_links');
        Canonical::require(is_array($settings) && count($settings) === 2 && !array_diff(array_keys($settings), ['enabled','key_file']), 'LINK_CONFIG_INVALID');
        $path = $settings['key_file'];
        Canonical::require(is_string($path) && str_starts_with($path, '/'), 'LINK_KEY_FILE_INVALID');
        // A long-lived worker must observe permission changes and replacement,
        // including after a prior denied read. Cached metadata is not trust.
        clearstatcache(true, $path);
        Canonical::require(!is_link($path) && is_file($path), 'LINK_KEY_FILE_INVALID');
        $stat = @lstat($path);
        Canonical::require(is_array($stat) && ($stat['mode'] & 077) === 0 && $stat['size'] > 0 && $stat['size'] <= 4096, 'LINK_KEY_FILE_INVALID');
        $bytes = @file_get_contents($path);
        Canonical::require(is_string($bytes) && strlen($bytes) <= 4096, 'LINK_KEY_FILE_INVALID');
        try {
            $data = StrictJson::object($bytes); StrictJson::exact($data, ['version','key_id','generation','key_hex']);
            Canonical::require($data->version === 'legacy-link-key/v1' && is_string($data->key_id)
                && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/D', $data->key_id)
                && is_string($data->generation) && Canonical::id($data->generation, '2147483647') === $data->generation
                && is_string($data->key_hex) && preg_match('/\A[a-f0-9]{64}\z/D', $data->key_hex), 'LINK_KEY_DATA_INVALID');
            return new self(hex2bin($data->key_hex), $data->key_id, $data->generation);
        } catch (\Throwable) { throw new LinkFailure('LINK_KEY_DATA_INVALID'); }
    }
    public function context(): array
    {
        $meta = ['version'=>'legacy-subscription-link/v1','key_id'=>$this->id,'generation'=>$this->generation];
        return $meta + ['key_check'=>hash_hmac('sha256', "txboard.legacy-subscription-link.key-context/v1\0".Canonical::json($meta), $this->key)];
    }
    public function digest(string $token): string
    {
        Canonical::require(strlen($token) >= 1 && strlen($token) <= 4096 && trim($token) !== '' && preg_match('//u', $token), 'LINK_TOKEN_INVALID');
        return hash_hmac('sha256', "txboard.legacy-subscription-link.token/v1\0".pack('N', strlen($token)).$token, $this->key);
    }
    public function __debugInfo(): array { return ['key_id'=>$this->id,'generation'=>$this->generation,'secret'=>'[redacted]']; }
}
