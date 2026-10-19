<?php

namespace App\Domains\Content;

use InvalidArgumentException;

/** Closed command input; a table name or PHP handler is never caller supplied. */
final class ContentInput
{
    public static function table(string $kind): string
    {
        return match ($kind) { 'notices' => 'tx_notice', 'knowledge' => 'tx_knowledge',
            default => throw new InvalidArgumentException('CONTENT_KIND_INVALID') };
    }

    public static function command(string $kind, string $action, array $input, string $key): void
    {
        self::table($kind);
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/Di', $key) !== 1) self::fail();
        $base = $kind === 'notices' ? ['title', 'content', 'img_url', 'tags', 'popup'] : ['title', 'body', 'category', 'language'];
        $base = [...$base, 'show', 'sort', 'audience', 'publish_at', 'expire_at'];
        $required = match ($action) {
            'create' => $base, 'update' => [...$base, 'content_id', 'expected_version'],
            'visibility' => ['content_id', 'expected_version', 'show'],
            'delete' => ['content_id', 'expected_version'], 'sort' => ['items'], default => throw new InvalidArgumentException('CONTENT_ACTION_INVALID'),
        };
        if (count($input) !== count($required) || array_diff($required, array_keys($input))) self::fail();
        if (isset($input['content_id'])) { self::id($input['content_id']); self::id($input['expected_version']); }
        if (array_key_exists('show', $input) && !is_bool($input['show'])) self::fail();
        if ($action === 'sort') {
            if (!is_array($input['items']) || !array_is_list($input['items']) || count($input['items']) < 1 || count($input['items']) > 1000) self::fail();
            $ids = [];
            foreach ($input['items'] as $item) {
                if (!is_array($item) || count($item) !== 2 || !isset($item['id'], $item['expected_version'])) self::fail();
                self::id($item['id']); self::id($item['expected_version']);
                if (isset($ids[$item['id']])) self::fail();
                $ids[$item['id']] = true;
            }
        }
        if (!in_array($action, ['create', 'update'], true)) return;
        self::text($input['title'], 255);
        self::text($input[$kind === 'notices' ? 'content' : 'body'], 20000);
        if (!is_int($input['sort']) || $input['sort'] < 0 || $input['sort'] > 2147483647
            || !in_array($input['audience'], ['authenticated', 'subscribed'], true)) self::fail();
        foreach (['publish_at', 'expire_at'] as $field) {
            if ($input[$field] !== null && (!is_int($input[$field]) || $input[$field] < 0 || $input[$field] > 253402300799)) self::fail();
        }
        if ($input['expire_at'] !== null && $input['publish_at'] !== null && $input['expire_at'] <= $input['publish_at']) self::fail();
        if ($kind === 'notices') {
            if (!is_bool($input['popup']) || !is_array($input['tags']) || !array_is_list($input['tags']) || count($input['tags']) > 30) self::fail();
            foreach ($input['tags'] as $tag) self::text($tag, 80);
            if ($input['img_url'] !== null) {
                self::text($input['img_url'], 2048);
                if (!filter_var($input['img_url'], FILTER_VALIDATE_URL) || !in_array(parse_url($input['img_url'], PHP_URL_SCHEME), ['https', 'http'], true)) self::fail();
            }
        } else { self::text($input['category'], 255); self::text($input['language'], 32); }
    }

    public static function id(mixed $value, bool $zero = false): void
    {
        if (!is_string($value) || preg_match($zero ? '/\A(?:0|[1-9][0-9]{0,18})\z/D' : '/\A[1-9][0-9]{0,18}\z/D', $value) !== 1
            || strlen($value) === 19 && strcmp($value, (string) PHP_INT_MAX) > 0) self::fail();
    }

    public static function canonical(array $value): string
    {
        foreach ($value as &$item) if (is_array($item)) $item = json_decode(self::canonical($item), true, 64, JSON_THROW_ON_ERROR);
        unset($item);
        if (!array_is_list($value)) ksort($value);
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function values(string $kind, array $input): array
    {
        $fields = $kind === 'notices' ? ['title', 'content', 'img_url', 'tags', 'popup'] : ['title', 'body', 'category', 'language'];
        $values = array_intersect_key($input, array_flip([...$fields, 'show', 'sort', 'audience', 'publish_at', 'expire_at']));
        if ($kind === 'notices') $values['tags'] = json_encode($values['tags'], JSON_THROW_ON_ERROR);
        return $values;
    }

    public static function text(mixed $value, int $limit): void
    {
        if (!is_string($value) || trim($value) === '' || preg_match('//u', $value) !== 1
            || strlen($value) > $limit * 4 || mb_strlen($value) > $limit
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1) self::fail();
    }
    private static function fail(): never { throw new InvalidArgumentException('CONTENT_INPUT_INVALID'); }
}
