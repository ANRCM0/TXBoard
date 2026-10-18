<?php

namespace Tests\Unit\Core;

use App\Core\Host\Execution\InvocationActivities;
use App\Core\Host\Execution\InvocationScope;
use App\Core\Kernel\Execution\ActorRef;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class InvocationScopeTest extends TestCase
{
    private const REQUEST_ID = 'fe0a72f4-a87e-4f13-a278-036bc875a80e';

    public function test_nested_worker_has_no_inherited_user_and_parent_is_restored_after_failure(): void
    {
        $scope = new InvocationScope;
        $scope->run('web', function () use ($scope): void {
            $scope->actor(new ActorRef('user', '42'));
            $parent = $scope->current();
            try {
                $scope->run('worker', function () use ($scope): void {
                    self::assertNull($scope->current()->actor);
                    $scope->actor(new ActorRef('system', 'queue'));
                    throw new RuntimeException('job failed');
                }, '6e874ffa-609a-4f30-9718-6ab7698d0541');
            } catch (RuntimeException) {
                self::assertSame($parent, $scope->current());
            }
        }, self::REQUEST_ID);
        self::assertNull($scope->current());
        $scope->run('web', function () use ($scope): void { self::assertNull($scope->current()->actor); }, self::REQUEST_ID);
    }

    public function test_duplicate_terminal_events_cannot_clear_a_parent_invocation(): void
    {
        $scope = new InvocationScope;
        $activities = new InvocationActivities;
        $scope->run('web', function () use ($scope, $activities): void {
            $parent = $scope->current();
            $job = new stdClass;
            $activities->begin($job, $scope, 'worker');
            self::assertSame('worker', $scope->current()->source);
            $activities->end($job);
            $activities->end($job);
            self::assertSame($parent, $scope->current());
        }, self::REQUEST_ID);
        self::assertNull($scope->current());
    }

    public function test_invalid_begin_does_not_corrupt_the_parent_stack(): void
    {
        $scope = new InvocationScope;
        $scope->run('web', function () use ($scope): void {
            $parent = $scope->current();
            try { $scope->begin('worker', 'invalid'); self::fail('Invalid ID accepted'); }
            catch (\InvalidArgumentException) { self::assertSame($parent, $scope->current()); }
        }, self::REQUEST_ID);
        self::assertNull($scope->current());
    }
}
