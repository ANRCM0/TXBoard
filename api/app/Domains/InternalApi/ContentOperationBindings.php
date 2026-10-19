<?php

namespace App\Domains\InternalApi;

/** Fixed image-owned ingress: names select only these reviewed owner methods. */
final class ContentOperationBindings
{
    public static function all(): array
    {
        $result = [];
        foreach (['notices' => 'notice', 'knowledge' => 'article'] as $kind => $prefix) {
            foreach (['list' => 'List', 'get' => 'Get'] as $suffix => $method) {
                $result['content.'.$kind.'.'.$suffix] = self::spec('admin', $prefix.$method, false);
                $result['content.user-'.$kind.'.'.$suffix] = self::spec('user', 'user'.ucfirst($prefix).$method, false);
            }
            foreach (['create' => 'Create', 'update' => 'Update', 'visibility.toggle' => 'Visibility', 'delete' => 'Delete', 'sort' => 'Sort'] as $suffix => $method) {
                $result['content.'.$kind.'.'.$suffix] = self::spec('admin', $prefix.$method, true);
            }
        }
        $result['content.knowledge.categories.list'] = self::spec('admin', 'categories', false);
        $result['content.user-knowledge.categories.list'] = self::spec('user', 'userCategories', false);
        $result['content.audit.list'] = self::spec('admin', 'audit', false);
        return $result;
    }

    public static function commands(): array
    {
        $result = [];
        foreach (self::all() as $id => $spec) {
            if ($spec['command'] !== null) $result[$id] = [$spec['command'], $spec['method'], 'domain'];
        }
        return $result;
    }

    private static function spec(string $actor, string $method, bool $write): array
    {
        return ['domains' => [$actor], 'actor' => $actor, 'mode' => $write ? 'domain' : 'read',
            'controller' => null, 'method' => $method, 'rules' => [], 'fields' => [], 'list' => false,
            'parameters' => [], 'routeParameters' => [],
            'permission' => $actor === 'admin' ? ($write ? 'content.manage' : 'content.read') : null,
            'handler' => $write ? null : ContentReadOperations::class,
            'command' => $write ? ContentMutationOperations::class : null];
    }
}
