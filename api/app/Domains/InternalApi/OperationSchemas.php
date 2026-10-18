<?php

namespace App\Domains\InternalApi;

use App\Core\Host\Contracts\TransportSchema;

final class OperationSchemas
{
    private static ?array $contract = null;

    public static function all(): array
    {
        return self::$contract ??= json_decode(file_get_contents(dirname(__DIR__, 3).'/resources/contracts/core-operations.v1.json'),
            true, 64, JSON_THROW_ON_ERROR);
    }

    public static function operation(string $operation): ?array
    {
        return self::all()['operations'][$operation] ?? null;
    }

    public static function validator(): TransportSchema
    {
        return new TransportSchema(self::all()['definitions']);
    }

    public static function apply(array $registry): array
    {
        foreach (self::all()['operations'] as $id => $contract) {
            if (!isset($registry[$id])) throw new \LogicException('CONTRACT_OPERATION_NOT_BOUND');
            $schema = self::expanded($contract['output']);
            $list = ($schema['type'] ?? null) === 'array';
            $registry[$id] = array_replace($registry[$id], [
                'domains' => $contract['domains'], 'actor' => $contract['actor'],
                'permission' => $contract['permission'], 'mode' => $contract['idempotency'],
                'rules' => [], 'fields' => self::fields($list ? $schema['items'] : $schema), 'list' => $list,
            ]);
        }
        return $registry;
    }

    private static function expanded(array $schema): array
    {
        return isset($schema['$ref']) ? self::all()['definitions'][substr($schema['$ref'], strlen('#/definitions/'))] : $schema;
    }

    private static function fields(array $schema): array
    {
        $schema = self::expanded($schema);
        if (!isset($schema['anyOf'])) return array_keys($schema['properties'] ?? []);
        return array_values(array_unique(array_merge(...array_map(self::fields(...), $schema['anyOf']))));
    }

    public static function input(string $operation, array $input): bool
    {
        $spec = self::operation($operation);
        return $spec === null || self::validator()->accepts($input, $spec['input']);
    }

    public static function output(string $operation, array $output): array
    {
        $spec = self::operation($operation);
        return $spec === null ? $output : self::validator()->project($output, $spec['output']);
    }

    public static function meta(string $operation, array $meta): array
    {
        $schema = self::operation($operation)['meta'] ?? null;
        return $schema === null ? $meta : self::validator()->project($meta, $schema);
    }
}
