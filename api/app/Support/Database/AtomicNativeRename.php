<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * Builds a single atomic MySQL RENAME TABLE statement from a reviewed plan.
 * Does not connect to the database or execute SQL.
 */
final class AtomicNativeRename
{
    public static function sql(array $plan, array $existingTables, string $direction): string
    {
        if (($plan['schemaVersion'] ?? null) !== 1 ||
            ($plan['kind'] ?? null) !== 'native-table-cutover-plan' ||
            !isset($plan['proposedRenames']) || !is_array($plan['proposedRenames'])) {
            throw new \InvalidArgumentException('Invalid native cutover plan');
        }
        if (!in_array($direction, ['up', 'down'], true)) {
            throw new \InvalidArgumentException('Invalid rename direction');
        }
        if (!empty($plan['blockers']) || ($plan['executable'] ?? null) !== true ||
            ($plan['requiresManualApproval'] ?? null) !== false) {
            throw new \RuntimeException('Cutover plan is blocked or not approved for execution');
        }
        if (!$plan['proposedRenames']) {
            throw new \RuntimeException('Empty rename plan');
        }
        $known = array_fill_keys($existingTables, true);
        $sources = [];
        $targets = [];
        $pairs = [];
        foreach ($plan['proposedRenames'] as $row) {
            $old = $row['from'] ?? null;
            $new = $row['to'] ?? null;
            if (!is_string($old) || !preg_match('/^v2_[a-z][a-z0-9_]*$/D', $old) ||
                !is_string($new) || $new !== 'tx_' . substr($old, 3)) {
                throw new \InvalidArgumentException('Unsafe rename mapping');
            }
            $source = $direction === 'up' ? $old : $new;
            $target = $direction === 'up' ? $new : $old;
            if (isset($sources[$source]) || isset($targets[$target]) ||
                !isset($known[$source]) || isset($known[$target])) {
                throw new \RuntimeException('Duplicate, missing source, or occupied target: ' . $source);
            }
            $sources[$source] = true;
            $targets[$target] = true;
            $pairs[] = '`' . $source . '` TO `' . $target . '`';
        }
        return 'RENAME TABLE ' . implode(', ', $pairs);
    }
}
