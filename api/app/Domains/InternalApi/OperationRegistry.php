<?php

namespace App\Domains\InternalApi;

use App\Http\Controllers\Txapi;

/** Closed executable vocabulary. No caller-supplied class, method, URL or actor. */
final class OperationRegistry
{
    public static function all(): array
    {
        $id = ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D',
            static function ($attribute, $value, $fail): void {
                if (is_string($value) && (strlen($value) > strlen((string) PHP_INT_MAX)
                    || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0))) {
                    $fail('Identifier out of range');
                }
            }];
        $trade = ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{1,128}\z/D'];
        $page = ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
        $user = ['user'];
        $admin = ['admin'];
        $both = ['user', 'admin'];
        $read = 'read';
        $write = 'workflow';
        $domain = 'domain';
        return OperationSchemas::apply([
            'system.capabilities.get' => self::spec($both, 'anonymous', $read, null, null, [], []),
            'identity.session.create' => self::spec($user, 'anonymous', 'once', Txapi\AuthController::class, 'login',
                ['email' => ['required', 'email:strict', 'max:255'], 'password' => ['required', 'string', 'min:8', 'max:255'],
                    'turnstile_token' => ['sometimes', 'string', 'max:8192'], 'recaptcha_v3_token' => ['sometimes', 'string', 'max:8192'],
                    'recaptcha_data' => ['sometimes', 'string', 'max:8192']],
                ['auth_data']),
            'identity.admin-session.create' => self::spec($admin, 'anonymous', 'once', Txapi\AuthController::class, 'adminLogin',
                ['email' => ['required', 'email:strict', 'max:255'], 'password' => ['required', 'string', 'min:8', 'max:255'],
                    'turnstile_token' => ['sometimes', 'string', 'max:8192'], 'recaptcha_v3_token' => ['sometimes', 'string', 'max:8192'],
                    'recaptcha_data' => ['sometimes', 'string', 'max:8192']],
                ['auth_data', 'is_admin', 'secure_path']),
            'identity.account.get' => self::spec($user, 'user', $read, null, 'account', [],
                ['id', 'email', 'telegram_id', 'subscription', 'credential_status'], handler: AccountReadOperations::class),
            'identity.admin.get' => self::spec($admin, 'admin', $read, null, 'account', [],
                ['id', 'email'], permission: 'identity.read', handler: AccountReadOperations::class),
            'identity.sessions.list' => self::spec($both, 'authenticated', $read, null, 'listing', [],
                ['id', 'name', 'abilities', 'last_used_at', 'created_at', 'updated_at', 'expires_at'], list: true,
                handler: SessionReadOperations::class),
            'identity.sessions.revoke' => self::spec($both, 'authenticated', $write, null, 'revoke',
                ['session_id' => $id], ['ok'], command: SessionMutationOperations::class),
            'identity.session.end' => self::spec($both, 'authenticated', 'once', null, 'end', [], ['ok'],
                command: SessionMutationOperations::class),
            'catalog.offers.list' => self::spec([], '', '', null, 'listing', [], [], handler: CatalogReadOperations::class),
            'subscription.mine.get' => self::spec([], '', '', null, 'subscription', [], [], handler: AccountReadOperations::class),
            'subscription.links.list' => self::spec($user, 'user', $read, Txapi\SubscriptionLinkController::class, 'index',
                ['after_id' => ['sometimes', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,17})\z/D']],
                ['schema', 'items', 'next_after_id']),
            'subscription.links.issue' => self::spec($user, 'user', 'domain-once', Txapi\SubscriptionLinkController::class, 'create',
                ['access_subject_id' => $id, 'expires_at' => ['required', 'integer', 'min:1']],
                ['schema', 'id', 'link_ref', 'subscription_id', 'access_subject_id', 'status', 'version', 'expires_at',
                    'revoked_at', 'created_at', 'link_token', 'token_delivered']),
            'subscription.links.revoke' => self::spec($user, 'user', $domain, Txapi\SubscriptionLinkController::class, 'revoke',
                ['link_ref' => ['required', 'string', 'regex:/\Alink_[a-f0-9]{32}\z/D']],
                ['schema', 'id', 'link_ref', 'subscription_id', 'access_subject_id', 'status', 'version', 'expires_at',
                    'revoked_at', 'created_at'], parameters: ['linkRef' => 'link_ref']),
            'commerce.orders.list' => self::spec([], '', '', null, 'listing', [], [], handler: OrderReadOperations::class),
            'commerce.orders.get' => self::spec([], '', '', null, 'find', [], [], handler: OrderReadOperations::class),
            'commerce.orders.create' => self::spec([], '', '', null, 'purchase', [], [], command: CommerceMutationOperations::class),
            'commerce.orders.checkout' => self::spec([], '', '', null, 'checkout', [], [], command: CommerceMutationOperations::class),
            'commerce.orders.cancel' => self::spec([], '', '', null, 'cancel', [], [], command: CommerceMutationOperations::class),
            'billing.wallet.get' => self::spec([], '', '', null, 'wallet', [], [], handler: BillingReadOperations::class),
            'billing.payment-attempts.get' => self::spec([], '', '', null, 'attempt', [], [], handler: BillingReadOperations::class),
            'billing.payment-attempts.query' => self::spec([], '', '', null, 'queryAttempt', [], [], command: CommerceMutationOperations::class),
            'billing.payment-methods.list' => self::spec([], '', '', null, 'methods', [], [], handler: BillingReadOperations::class),
            'billing.recharges.create' => self::spec([], '', '', null, 'recharge', [], [], command: CommerceMutationOperations::class),
            'billing.recharges.list' => self::spec([], '', '', null, 'recharges', [], [], handler: BillingReadOperations::class),
            'billing.recharges.get' => self::spec([], '', '', null, 'recharge', [], [], handler: BillingReadOperations::class),
            'billing.recharges.checkout' => self::spec([], '', '', null, 'rechargeCheckout', [], [], command: CommerceMutationOperations::class),
            'usage.mine.list' => self::spec([], '', '', null, 'listing', [], [], handler: UsageReadOperations::class),
            'settings.public.get' => self::spec($both, 'anonymous', $read, Txapi\PublicController::class, 'siteConfig', [],
                ['frontend_theme', 'tos_url', 'email_whitelist_suffix', 'captcha_type', 'recaptcha_site_key',
                    'recaptcha_v3_site_key', 'recaptcha_v3_score_threshold', 'turnstile_site_key',
                    'app_name', 'app_description', 'app_url', 'logo', 'tos_url', 'is_email_verify', 'is_invite_force',
                    'is_recaptcha', 'is_captcha', 'stop_register', 'login_with_mail_link_enable',
                    'try_out_enable', 'try_out_plan_id', 'traffic_warn_rate']),
            'network.nodes.list' => self::spec($admin, 'admin', $read, Txapi\Admin\NetworkNodeAdminController::class, 'index',
                $page, ['id', 'name', 'type', 'host', 'port', 'server_port', 'group_ids', 'route_ids', 'tags',
                    'parent_id', 'machine_id', 'show', 'enabled', 'sort', 'u', 'd'], list: true, permission: 'network.nodes.read'),
            'network.machines.list' => self::spec($admin, 'admin', $read, Txapi\Admin\NetworkMachineAdminController::class, 'index',
                $page, ['id', 'name', 'is_active', 'image_channel', 'last_seen_at', 'servers_count',
                    'created_at', 'updated_at'], list: true, permission: 'network.machines.read'),
            'catalog.plans.publish' => self::spec($admin, 'admin', $domain, Txapi\Admin\NativePlanAdminController::class, 'publish',
                array_replace(Txapi\Admin\NativePlanAdminController::publicationRules(), [
                    'plan_id' => array_replace($id, [0 => 'sometimes']),
                    'group_id' => ['sometimes', 'nullable', 'string', 'regex:/\A[1-9][0-9]{0,9}\z/D'],
                ]), ['schema', 'plan_id', 'publication'], permission: 'catalog.publish'),
            'catalog.offers.availability.update' => self::spec($admin, 'admin', $write,
                Txapi\Admin\NativePlanAdminController::class, 'availability',
                ['offer_id' => $id, 'show' => ['required', 'boolean'], 'sell' => ['required', 'boolean'],
                    'renew' => ['required', 'boolean'],
                    'capacity_limit' => ['present', 'nullable', 'integer', 'min:0', 'max:4294967295'],
                    'expected_version' => $id],
                ['offer_id', 'status', 'row_version', 'show', 'sell', 'renew', 'capacity_limit'],
                parameters: ['offerId' => 'offer_id'], permission: 'catalog.publish'),
            'billing.refunds.create' => self::spec($admin, 'admin', $domain, Txapi\Admin\NativeBillingAdminController::class, 'refund',
                ['user_id' => $id, 'source_type' => ['required', 'in:receipt,wallet_payment'], 'source_id' => $id,
                    'amount_minor' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
                    'reason' => ['required', 'string', 'regex:/\A[A-Za-z0-9_.:-]{1,64}\z/D']],
                ['refund_id', 'status', 'external_refund', 'user_id', 'source_type', 'source_id', 'receipt_id',
                    'order_payment_id', 'order_id', 'source_journal_id', 'journal_id', 'amount_minor', 'cumulative_minor',
                    'currency', 'destination', 'reason', 'entitlement_effect', 'subscription_id', 'created_at'],
                permission: 'billing.refund'),
            'billing.reconciliation.get' => self::spec($admin, 'admin', $read, Txapi\Admin\NativeBillingAdminController::class, 'reconcile',
                ['user_id' => $id, 'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
                    'after_id' => ['sometimes', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,17})\z/D']],
                ['user_id', 'scope', 'system_account_projections', 'account_reconciliation', 'read_only', 'complete', 'issues',
                    'incomplete_reasons', 'checked', 'next_after_id', 'unresolved_attempts', 'refund_liabilities', 'pending_work'],
                permission: 'billing.reconcile'),
            'settings.site.get' => self::spec($admin, 'admin', $read, Txapi\Admin\SettingsAdminController::class, 'group', [],
                ['site'], permission: 'settings.read', routeParameters: ['group' => 'site']),
            'settings.site.update' => self::spec($admin, 'admin', $write, Txapi\Admin\SettingsAdminController::class, 'save',
                ['app_name' => ['sometimes', 'string', 'min:1', 'max:255'],
                    'app_description' => ['sometimes', 'nullable', 'string', 'max:2048'],
                    'logo' => ['sometimes', 'nullable', 'url', 'max:2048'],
                    'tos_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
                    'stop_register' => ['sometimes', 'boolean']],
                ['ok'], permission: 'settings.write'),
            ...self::subscriptionOperations($admin, $id),
        ]);
    }

    public static function capabilities(string $domain, array $allowed): array
    {
        $result = [];
        foreach (self::all() as $id => $spec) {
            if (!in_array($domain, $spec['domains'], true) || !in_array($id, $allowed, true)) continue;
            $contract = OperationSchemas::operation($id);
            $result[] = ['id' => $id, 'version' => '1', 'actor' => $spec['actor'],
                'permission' => $spec['permission'], 'idempotency' => $spec['mode'],
                'input_fields' => $contract !== null ? array_keys($contract['input']['properties'])
                    : array_values(array_filter(array_keys($spec['rules']), static fn ($field): bool => !str_contains($field, '.'))),
                'input_schema' => $contract !== null ? $contract['input'] + ['definitions' => OperationSchemas::all()['definitions']]
                    : self::inputSchema($spec['rules']), 'output_fields' => $spec['fields'],
                'output_schema' => $contract !== null ? $contract['output'] + ['definitions' => OperationSchemas::all()['definitions']] : null,
                'errors' => $contract['errors'] ?? [],
                'max_request_bytes' => (int) config('internal_api.max_request_bytes'),
                'deadline_seconds' => (int) config('internal_api.deadline_seconds')];
        }
        return ['contract' => 'native-internal-api/v1', 'domain' => $domain, 'operations' => $result,
            'unavailable' => ['platform.modules.lifecycle', 'network.nodes.credentials.get']];
    }

    private static function spec(array $domains, string $actor, string $mode, ?string $controller, ?string $method,
        array $rules, array $fields, bool $list = false, array $parameters = [], ?string $permission = null,
        array $routeParameters = [], ?string $handler = null, ?string $command = null): array
    {
        return compact('domains', 'actor', 'mode', 'controller', 'method', 'rules', 'fields', 'list', 'parameters',
            'permission', 'routeParameters', 'handler', 'command');
    }

    private static function inputSchema(array $rules): array
    {
        $properties = [];
        $required = [];
        foreach ($rules as $field => $constraints) {
            if (str_contains($field, '.')) continue;
            $constraints = is_array($constraints) ? $constraints : explode('|', $constraints);
            $type = in_array('integer', $constraints, true) ? 'integer'
                : (in_array('boolean', $constraints, true) ? 'boolean' : 'string');
            $schema = ['type' => in_array('nullable', $constraints, true) ? [$type, 'null'] : $type];
            foreach ($constraints as $constraint) {
                if (!is_string($constraint)) continue;
                if ($constraint === 'array' || str_starts_with($constraint, 'array:')) {
                    $schema = ['type' => str_starts_with($constraint, 'array:') ? 'object' : 'array'];
                    if ($schema['type'] === 'object') {
                        $schema['additionalProperties'] = false;
                        $schema['properties'] = array_fill_keys(explode(',', substr($constraint, 6)), ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,18})$']);
                    } else $schema['items'] = ['type' => 'string'];
                }
                if (str_starts_with($constraint, 'in:')) {
                    $values = explode(',', substr($constraint, 3));
                    $schema['enum'] = $type === 'integer' ? array_map('intval', $values) : $values;
                }
                if (str_starts_with($constraint, 'regex:/') && str_ends_with($constraint, '/D')) {
                    $schema['pattern'] = str_replace(['\\A', '\\z'], ['^', '$'], substr($constraint, 7, -2));
                }
                if (str_starts_with($constraint, 'min:') || str_starts_with($constraint, 'max:')) {
                    [$bound, $number] = explode(':', $constraint, 2);
                    $key = match ($schema['type'] ?? '') {
                        'object' => $bound === 'min' ? 'minProperties' : 'maxProperties',
                        'array' => $bound === 'min' ? 'minItems' : 'maxItems',
                        default => $type === 'integer' ? ($bound === 'min' ? 'minimum' : 'maximum')
                            : ($bound === 'min' ? 'minLength' : 'maxLength'),
                    };
                    if (ctype_digit($number)) $schema[$key] = (int) $number;
                }
            }
            if (in_array('required', $constraints, true) || in_array('present', $constraints, true)) $required[] = $field;
            $properties[$field] = $schema;
        }
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties ?: (object) [],
            'required' => $required];
    }

    private static function orderFields(): array
    {
        return ['id', 'trade_no', 'plan_id', 'period', 'type', 'status', 'amount_minor', 'plan', 'paid_at', 'created_at'];
    }

    private static function rechargeFields(): array
    {
        return ['recharge_id', 'trade_no', 'status', 'amount_minor', 'fee_minor', 'total_minor', 'currency', 'payment_method_id',
            'paid_at', 'created_at', 'attempt', 'payment'];
    }

    private static function subscriptionOperations(array $admin, array $id): array
    {
        $operations = [];
        foreach (['suspend', 'resume', 'revoke', 'expire', 'reset'] as $kind) {
            $rules = ['subscription_id' => $id, 'expected_version' => $id];
            if ($kind === 'suspend') $rules['reason'] = ['sometimes', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}\z/D'];
            $operations['subscription.lifecycle.'.$kind] = self::spec($admin, 'admin', 'domain',
                Txapi\Admin\SubscriptionOperationsController::class, 'execute', $rules,
                ['schema', 'user_id', 'subscription_id', 'terms_id', 'usage_period_id', 'subscription_version',
                    'period_version', 'status', 'expires_at', 'applied_at', 'transition_id', 'previous_period_id', 'quota_adjustment_id'],
                parameters: ['id' => 'subscription_id'], permission: 'subscription.manage', routeParameters: ['operation' => $kind]);
        }
        return $operations;
    }

    private static function entitlementFields(): array
    {
        return ['schema', 'authorized', 'reason', 'user_id', 'subscription_id', 'subscription_version', 'plan_id',
            'terms_id', 'terms_version', 'plan_revision_id', 'usage_period_id', 'period_terms_id', 'period_version',
            'group_id', 'speed_limit_mbps', 'device_limit', 'expires_at', 'next_reset_at', 'quota_bytes', 'consumed_bytes',
            'remaining_bytes', 'upload_bytes', 'download_bytes', 'credential_status', 'delivery_formats'];
    }
}
