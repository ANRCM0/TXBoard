<?php

namespace App\Domains\InternalApi;

/** Image-owned bindings. Request data never selects a controller or method. */
final class AdminOperationBindings
{
    public static function all(): array
    {
        $source = json_decode(file_get_contents(dirname(__DIR__, 3).'/resources/contracts/admin-bindings.v1.json'),
            true, 64, JSON_THROW_ON_ERROR);
        $result = [];
        foreach ($source['bindings'] as $id => $binding) {
            $contract = OperationSchemas::operation($id);
            if ($contract === null || $contract['domains'] !== ['admin'] || $contract['actor'] !== 'admin'
                || !preg_match('/\A[A-Za-z][A-Za-z0-9]*Controller\z/D', $binding['controller'])
                || !preg_match('/\A[a-z][A-Za-z0-9]*\z/D', $binding['method'])) {
                throw new \LogicException('ADMIN_BINDING_INVALID');
            }
            $result[$id] = [
                'domains' => ['admin'], 'actor' => 'admin', 'mode' => $contract['idempotency'],
                'controller' => 'App\\Http\\Controllers\\Txapi\\Admin\\'.$binding['controller'],
                'method' => $binding['method'], 'rules' => [], 'fields' => [], 'list' => false,
                'parameters' => $binding['parameters'], 'routeParameters' => $binding['routeParameters'],
                'permission' => $contract['permission'], 'handler' => null, 'command' => null,
                'payload' => $binding['payload'],
                'adminDatabaseWrite' => $contract['idempotency'] !== 'read'
                    && in_array($binding['controller'], ['NetworkGroupAdminController', 'NetworkRouteAdminController',
                        'NetworkNodeAdminController', 'NetworkMachineAdminController', 'ContentAdminController',
                        'AccessAuditAdminController'], true)
                    || $binding['controller'] === 'MailTemplateAdminController'
                        && in_array($binding['method'], ['save', 'reset'], true),
            ];
        }
        return $result;
    }
}
