<?php

namespace App\Services\Module;

use InvalidArgumentException;

final readonly class ModuleHealthDetails
{
    private const MAX_CHECKS = 32;

    /**
     * @param array<string, bool|null> $checks
     */
    public function __construct(
        public array $checks,
        public int $observedAt,
    ) {
        if ($checks === [] || count($checks) > self::MAX_CHECKS) {
            throw new InvalidArgumentException('Module health checks must contain between 1 and 32 entries');
        }

        foreach ($checks as $name => $value) {
            if (
                !is_string($name)
                || !preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $name)
            ) {
                throw new InvalidArgumentException('Invalid Module health check name');
            }

            if (!is_bool($value) && $value !== null) {
                throw new InvalidArgumentException('Module health check values must be boolean or null');
            }
        }

        if ($observedAt <= 0) {
            throw new InvalidArgumentException('Module health observed_at must be a positive timestamp');
        }
    }

    /**
     * @param array<string, bool|null> $checks
     */
    public static function fromChecks(array $checks, ?int $observedAt = null): self
    {
        return new self(
            checks: $checks,
            observedAt: $observedAt ?? time(),
        );
    }

    public function toArray(): array
    {
        return [
            'checks' => $this->checks,
            'observed_at' => $this->observedAt,
        ];
    }
}
