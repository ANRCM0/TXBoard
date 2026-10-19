<?php

namespace App\Domains\Browser;

use App\Domains\InternalApi\OperationSchemas;

final class BrowserSurface
{
    public function operations(string $domain): array
    {
        if (!config('browser.enabled') || !in_array($domain, ['user', 'admin'], true)
            || !config('browser.'.$domain.'_enabled')) return [];
        $surface = json_decode(file_get_contents(resource_path('contracts/browser-surfaces.v1.json')), true, 32, JSON_THROW_ON_ERROR);
        if (($surface['version'] ?? null) !== 1 || !is_array($surface[$domain] ?? null)) throw new \LogicException('BROWSER_SURFACE_INVALID');
        return array_values(array_filter($surface[$domain], static function (string $id) use ($domain): bool {
            $spec = OperationSchemas::operation($id);
            if ($spec === null || !in_array($domain, $spec['domains'], true)) throw new \LogicException('BROWSER_SURFACE_INVALID');
            return $domain !== 'user' || config('browser.commerce_enabled') || !self::commerce($id);
        }));
    }

    public static function commerce(string $id): bool
    {
        return OperationSchemas::operation($id)['idempotency'] !== 'read'
            && (str_starts_with($id, 'commerce.') || str_starts_with($id, 'billing.'));
    }
}
