<?php

namespace App\Services\Plugin;

use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Illuminate\Support\Facades\App;

class HookManager
{
    /**
     * Container for storing action hooks
     * 
     * Uses request() to store hook data within the cycle to avoid Octane memory leaks
     */
    public static function getActions(): array
    {
        if (!App::has('hook.actions')) {
            App::instance('hook.actions', []);
        }

        return App::make('hook.actions');
    }

    /**
     * Container for storing filter hooks
     */
    public static function getFilters(): array
    {
        if (!App::has('hook.filters')) {
            App::instance('hook.filters', []);
        }

        return App::make('hook.filters');
    }

    /**
     * Set action hooks
     */
    protected static function setActions(array $actions): void
    {
        App::instance('hook.actions', $actions);
    }

    /**
     * Set filter hooks
     */
    protected static function setFilters(array $filters): void
    {
        App::instance('hook.filters', $filters);
    }

    /**
     * Register callbacks from a known plugin within its ownership scope.
     * The try/finally restores the prior owner even if plugin boot throws.
     */
    public static function withOwner(string $code, callable $callback): mixed
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $code) !== 1) {
            throw new \InvalidArgumentException('HOOK_OWNER_INVALID');
        }
        $previous = App::bound('hook.current_owner')
            ? App::make('hook.current_owner') : null;
        App::instance('hook.current_owner', $code);
        try {
            return $callback();
        } finally {
            App::instance('hook.current_owner', $previous);
        }
    }

    public static function removeOwner(string $code): void
    {
        $owned = App::bound('hook.owned') ? App::make('hook.owned') : [];
        $actions = self::getActions();
        $filters = self::getFilters();
        foreach ($owned[$code] ?? [] as [$kind, $hook, $priority, $key]) {
            if ($kind === 'action') {
                unset($actions[$hook][$priority][$key]);
                if (empty($actions[$hook][$priority])) {
                    unset($actions[$hook][$priority]);
                }
                if (empty($actions[$hook])) {
                    unset($actions[$hook]);
                }
            } else {
                unset($filters[$hook][$priority][$key]);
                if (empty($filters[$hook][$priority])) {
                    unset($filters[$hook][$priority]);
                }
                if (empty($filters[$hook])) {
                    unset($filters[$hook]);
                }
            }
        }
        unset($owned[$code]);
        self::setActions($actions);
        self::setFilters($filters);
        App::instance('hook.owned', $owned);
    }

    private static function registrationKey(string $kind, string $hook, int $priority, callable $callback): string
    {
        $id = self::getCallableId($callback);
        $owner = App::bound('hook.current_owner') ? App::make('hook.current_owner') : null;
        if (!is_string($owner) || $owner === '') {
            return $id;
        }

        $key = 'plugin:' . $owner . ':' . $id;
        $owned = App::bound('hook.owned') ? App::make('hook.owned') : [];
        $owned[$owner][$kind . ':' . $hook . ':' . $priority . ':' . $key] =
            [$kind, $hook, $priority, $key];
        App::instance('hook.owned', $owned);
        return $key;
    }

    private static function owner(string $kind, string $hook, int $priority, string $key): ?string
    {
        $owned = App::bound('hook.owned') ? App::make('hook.owned') : [];
        $registration = $kind.':'.$hook.':'.$priority.':'.$key;
        foreach ($owned as $owner => $entries) {
            if (isset($entries[$registration])) return $owner;
        }
        return null;
    }

    private static function invoke(string $kind, string $hook, int $priority, string $key,
        callable $callback, array $arguments): mixed
    {
        $previous = App::bound('hook.current_owner') ? App::make('hook.current_owner') : null;
        App::instance('hook.current_owner', self::owner($kind, $hook, $priority, $key));
        try {
            return $callback(...$arguments);
        } finally {
            App::instance('hook.current_owner', $previous);
        }
    }

    /** Diagnostic metadata only: never serialize callbacks, captures or payloads. */
    public static function describe(): array
    {
        $result = [];
        foreach (['action' => self::getActions(), 'filter' => self::getFilters()] as $kind => $hooks) {
            ksort($hooks);
            foreach ($hooks as $hook => $priorities) {
                ksort($priorities);
                foreach ($priorities as $priority => $callbacks) {
                    foreach ($callbacks as $key => $_callback) {
                        $result[] = ['kind' => $kind, 'hook' => $hook, 'priority' => $priority,
                            'owner' => self::owner($kind, $hook, $priority, $key)];
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Generate unique identifier for callback
     * 
     * @param callable $callback
     * @return string
     */
    protected static function getCallableId(callable $callback): string
    {
        if (is_object($callback)) {
            return spl_object_hash($callback);
        }

        if (is_array($callback) && count($callback) === 2) {
            [$class, $method] = $callback;

            if (is_object($class)) {
                return spl_object_hash($class) . '::' . $method;
            } else {
                return (string) $class . '::' . $method;
            }
        }

        if (is_string($callback)) {
            return $callback;
        }

        return 'callable_' . uniqid();
    }

    /**
     * Intercept response
     *
     * @param SymfonyResponse|string|array $response New response content
     * @return never
     * @throws \Exception
     */
    public static function intercept(SymfonyResponse|string|array $response): never
    {
        if (is_string($response)) {
            $response = response($response);
        } elseif (is_array($response)) {
            $response = response()->json($response);
        }

        throw new InterceptResponseException($response);
    }

    /**
     * Trigger action hook
     *
     * @param string $hook Hook name
     * @param mixed $payload Data passed to hook
     * @return void
     */
    public static function call(string $hook, mixed $payload = null): void
    {
        $actions = self::getActions();

        if (!isset($actions[$hook])) {
            return;
        }

        ksort($actions[$hook]);

        foreach ($actions[$hook] as $priority => $callbacks) {
            foreach ($callbacks as $key => $callback) {
                if ((self::getActions()[$hook][$priority][$key] ?? null) !== $callback) continue;
                self::invoke('action', $hook, $priority, $key, $callback, [$payload]);
            }
        }
    }

    /**
     * Trigger filter hook
     *
     * @param string $hook Hook name
     * @param mixed $value Value to filter
     * @param mixed ...$args Other parameters
     * @return mixed
     */
    public static function filter(string $hook, mixed $value, mixed ...$args): mixed
    {
        $filters = self::getFilters();

        if (!isset($filters[$hook])) {
            return $value;
        }

        ksort($filters[$hook]);

        $result = $value;
        foreach ($filters[$hook] as $priority => $callbacks) {
            foreach ($callbacks as $key => $callback) {
                if ((self::getFilters()[$hook][$priority][$key] ?? null) !== $callback) continue;
                $result = self::invoke('filter', $hook, $priority, $key, $callback, [$result, ...$args]);
            }
        }

        return $result;
    }

    /**
     * Register action hook listener
     *
     * @param string $hook Hook name
     * @param callable $callback Callback function
     * @param int $priority Priority
     * @return void
     */
    public static function register(string $hook, callable $callback, int $priority = 20): void
    {
        $actions = self::getActions();

        if (!isset($actions[$hook])) {
            $actions[$hook] = [];
        }

        if (!isset($actions[$hook][$priority])) {
            $actions[$hook][$priority] = [];
        }

        $actions[$hook][$priority][self::registrationKey('action', $hook, $priority, $callback)] = $callback;

        self::setActions($actions);
    }

    /**
     * Register filter hook
     *
     * @param string $hook Hook name
     * @param callable $callback Callback function
     * @param int $priority Priority
     * @return void
     */
    public static function registerFilter(string $hook, callable $callback, int $priority = 20): void
    {
        $filters = self::getFilters();

        if (!isset($filters[$hook])) {
            $filters[$hook] = [];
        }

        if (!isset($filters[$hook][$priority])) {
            $filters[$hook][$priority] = [];
        }

        $filters[$hook][$priority][self::registrationKey('filter', $hook, $priority, $callback)] = $callback;

        self::setFilters($filters);
    }

    /**
     * Remove hook listener
     *
     * @param string $hook Hook name
     * @param callable|null $callback Callback function
     * @return void
     */
    public static function remove(string $hook, ?callable $callback = null): void
    {
        $scope = App::bound('hook.current_owner') ? App::make('hook.current_owner') : null;
        $callbackId = $callback === null ? null : self::getCallableId($callback);
        $owned = App::bound('hook.owned') ? App::make('hook.owned') : [];
        foreach (['action' => self::getActions(), 'filter' => self::getFilters()] as $kind => $hooks) {
            foreach ($hooks[$hook] ?? [] as $priority => $callbacks) {
                foreach ($callbacks as $key => $_callback) {
                    $owner = self::owner($kind, $hook, $priority, $key);
                    // A plugin may remove its own callbacks; the host retains global cleanup.
                    if ($scope !== null && $scope !== $owner) continue;
                    if ($callbackId !== null && $key !== ($owner === null ? $callbackId : 'plugin:'.$owner.':'.$callbackId)) continue;
                    unset($hooks[$hook][$priority][$key]);
                    if ($owner !== null) {
                        unset($owned[$owner][$kind.':'.$hook.':'.$priority.':'.$key]);
                        if (empty($owned[$owner])) unset($owned[$owner]);
                    }
                }
                if (empty($hooks[$hook][$priority])) unset($hooks[$hook][$priority]);
            }
            if (empty($hooks[$hook])) unset($hooks[$hook]);
            if ($kind === 'action') self::setActions($hooks);
            else self::setFilters($hooks);
        }
        App::instance('hook.owned', $owned);
    }

    /**
     * Check if hook exists
     *
     * @param string $hook Hook name
     * @return bool
     */
    public static function hasHook(string $hook): bool
    {
        $actions = self::getActions();
        $filters = self::getFilters();

        return isset($actions[$hook]) || isset($filters[$hook]);
    }

    /**
     * Clear all hooks (called when Octane resets)
     */
    public static function reset(): void
    {
        App::instance('hook.actions', []);
        App::instance('hook.filters', []);
        App::instance('hook.owned', []);
        App::instance('hook.current_owner', null);
    }
}
