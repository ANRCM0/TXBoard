<?php

namespace App\Domains\Billing;

final class AdminOrderFilterFields
{
    public const FIELDS = [
        'id' => ['type' => 'number', 'positive' => true], 'user_id' => ['type' => 'number', 'positive' => true],
        'plan_id' => ['type' => 'number', 'positive' => true], 'trade_no' => ['type' => 'text', 'max' => 128],
        'status' => ['type' => 'enum', 'values' => ['0', '1', '2', '3', '4']],
        'type' => ['type' => 'enum', 'values' => ['1', '2', '3', '4']],
        'period' => ['type' => 'enum', 'values' => ['monthly', 'quarterly', 'half_yearly', 'yearly', 'two_yearly', 'three_yearly', 'onetime', 'reset_traffic']],
        'created_at' => ['type' => 'timestamp'], 'paid_at' => ['type' => 'timestamp', 'nullable' => true],
        'amount_minor' => ['type' => 'number'], 'currency' => ['type' => 'text', 'max' => 3],
    ];
    public const SORTS = ['id', 'created_at', 'paid_at', 'amount_minor'];

    public static function validate(Contracts\AdminOrderListQuery $query): void
    {
        \App\Core\Query\BoundedListFilters::validate($query->filters, self::FIELDS);
        if (!in_array($query->sortBy, self::SORTS, true)) throw new \InvalidArgumentException('ADMIN_ORDER_QUERY_INVALID');
        $hasCurrency = false; $needsCurrency = $query->sortBy === 'amount_minor';
        foreach ($query->filters as $filter) {
            if ($filter['field'] === 'currency') {
                if ($filter['operator'] !== 'eq' || !preg_match('/\A[A-Z]{3}\z/D', $filter['value'])) throw new \InvalidArgumentException('ADMIN_ORDER_CURRENCY_INVALID');
                $hasCurrency = true;
            }
            if ($filter['field'] === 'amount_minor') $needsCurrency = true;
        }
        if ($needsCurrency && !$hasCurrency) throw new \InvalidArgumentException('ADMIN_ORDER_CURRENCY_REQUIRED');
    }
}
