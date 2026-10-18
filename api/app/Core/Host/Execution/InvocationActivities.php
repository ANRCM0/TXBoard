<?php

namespace App\Core\Host\Execution;

use WeakMap;

/** Matches terminal events to the original scope, including synchronous nested queue jobs. */
final class InvocationActivities
{
    private WeakMap $activities;

    public function __construct() { $this->activities = new WeakMap; }

    public function begin(object $key, InvocationScope $scope, string $source): void
    {
        $this->end($key);
        $this->activities[$key] = [$scope, $scope->begin($source)];
    }

    public function end(object $key): void
    {
        if (!isset($this->activities[$key])) return;
        [$scope, $token] = $this->activities[$key];
        unset($this->activities[$key]);
        $scope->end($token);
    }
}
