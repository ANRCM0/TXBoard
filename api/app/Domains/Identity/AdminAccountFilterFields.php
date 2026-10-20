<?php

namespace App\Domains\Identity;

final class AdminAccountFilterFields
{
    public const FIELDS = [
        'id' => ['type' => 'number', 'positive' => true], 'email' => ['type' => 'text', 'max' => 254],
        'banned' => ['type' => 'boolean'], 'is_admin' => ['type' => 'boolean'],
        'created_at' => ['type' => 'timestamp', 'nullable' => true], 'last_login_at' => ['type' => 'timestamp', 'nullable' => true],
    ];
    public const SORTS = ['id', 'email', 'created_at', 'last_login_at'];

    public static function validate(Contracts\AdminAccountListQuery $query): void
    {
        \App\Core\Query\BoundedListFilters::validate($query->filters, self::FIELDS);
        if (!in_array($query->sortBy, self::SORTS, true)) throw new \InvalidArgumentException('ADMIN_ACCOUNT_QUERY_INVALID');
    }
}
