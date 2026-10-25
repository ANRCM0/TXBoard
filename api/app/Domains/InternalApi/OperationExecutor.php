<?php

namespace App\Domains\InternalApi;

use App\Core\Http\TxapiResponse;
use App\Domains\Billing\OrderIdempotency;
use App\Http\Middleware\Admin;
use App\Http\Middleware\AdminPath;
use App\Http\Middleware\TxapiUser;
use App\Http\Requests\Passport\AuthLogin;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class OperationExecutor
{
    public function execute(Request $request, string $domain, string $operation): JsonResponse
    {
        $service = $request->attributes->get('native_internal_service');
        if (!$service || $service->security_domain !== $domain) {
            return $this->error($request, 'SERVICE_UNAUTHORIZED', 403);
        }
        $spec = OperationRegistry::all()[$operation] ?? null;
        if (!$spec) return $this->error($request, 'UNKNOWN_OPERATION', 404);
        $allowed = json_decode($service->operations_json, true, flags: JSON_THROW_ON_ERROR);
        if (!in_array($domain, $spec['domains'], true) || !in_array($operation, $allowed, true)) {
            return $this->error($request, 'FORBIDDEN', 403);
        }
        if (strlen($request->getContent()) > (int) config('internal_api.max_request_bytes')
            || !$request->isJson() || $request->query->count() !== 0) {
            return $this->error($request, 'INVALID_REQUEST', 400);
        }
        try {
            $envelope = StrictJson::object($request->getContent(), ['input']);
        } catch (\JsonException) {
            return $this->error($request, 'INVALID_REQUEST', 400);
        }
        if (array_diff(array_keys($envelope), ['contract_version', 'request_id', 'deadline_at', 'input'])) {
            return $this->error($request, 'INVALID_REQUEST', 400);
        }
        $validator = Validator::make($envelope, [
            'contract_version' => ['required', 'in:1'], 'request_id' => ['required', 'uuid'],
            'deadline_at' => ['required', 'integer'], 'input' => ['present', 'array'],
        ]);
        if ($validator->fails() || ($envelope['contract_version'] ?? null) !== '1'
            || !is_int($envelope['deadline_at'] ?? null)) {
            return $this->error($request, 'INVALID_REQUEST', 400);
        }
        app(InternalAudit::class)->trace($request, $envelope['request_id']);
        if ($envelope['deadline_at'] <= time()) return $this->error($request, 'DEADLINE_EXCEEDED', 504);
        if ($envelope['deadline_at'] > time() + (int) config('internal_api.deadline_seconds')) {
            return $this->error($request, 'INVALID_REQUEST', 400);
        }
        return $this->executeAdmitted($request, $domain, $operation, $spec, $allowed, $envelope,
            'native-internal:'.hash('sha256', $service->service_id.':'.$operation));
    }

    /** Browser admission supplies a compiled surface, never a fabricated internal service identity. */
    public function executeBrowser(Request $request, string $domain, string $operation, array $input): JsonResponse
    {
        if ($request->attributes->get('core_browser_admitted') !== true
            || !in_array($operation, app(\App\Domains\Browser\BrowserSurface::class)->operations($domain), true)) {
            return $this->error($request, 'FORBIDDEN', 403);
        }
        $spec = OperationRegistry::all()[$operation] ?? null;
        if ($spec === null || !in_array($domain, $spec['domains'], true)) return $this->error($request, 'UNKNOWN_OPERATION', 404);
        $trace = TxapiResponse::requestId($request);
        return $this->executeAdmitted($request, $domain, $operation, $spec, [],
            ['input' => $input, 'request_id' => $trace, 'deadline_at' => time() + (int) config('browser.deadline_seconds', 30)],
            'native-browser:'.hash('sha256', $request->attributes->get('core_browser_client_ip').':'.$operation));
    }

    private function executeAdmitted(Request $request, string $domain, string $operation, array $spec,
        array $allowed, array $envelope, string $limitKey): JsonResponse
    {
        $input = $envelope['input'];
        $inputFields = array_values(array_filter(array_keys($spec['rules']), static fn ($field): bool => !str_contains($field, '.')));
        $contract = OperationSchemas::operation($operation);
        if ($contract !== null ? !OperationSchemas::input($operation, $input)
            : (array_diff(array_keys($input), $inputFields) || Validator::make($input, $spec['rules'])->fails())) {
            return $this->error($request, 'INVALID_REQUEST', 422);
        }
        $limit = $request->attributes->get('core_browser_admitted') !== true
            && $spec['actor'] === 'anonymous' && str_contains($operation, 'session.create') ? 10 : 120;
        if (RateLimiter::tooManyAttempts($limitKey, $limit)) return $this->error($request, 'RATE_LIMITED', 429);
        RateLimiter::hit($limitKey, 60);
        try {
            $actor = $this->actor($request, $domain, $spec);
            app(\App\Core\Host\Execution\InvocationScope::class)->correlate(
                $envelope['request_id'], $envelope['deadline_at']);
            app(InternalAudit::class)->actor($request, $actor?->id, $envelope['request_id']);
            if ($envelope['deadline_at'] <= time()) return $this->error($request, 'DEADLINE_EXCEEDED', 504);
            if ($operation === 'system.capabilities.get') {
                $response = TxapiResponse::success($request, OperationRegistry::capabilities($domain, $allowed));
            } else {
                $response = $this->dispatch($request, $operation, $spec, $input, $actor);
            }
        } catch (OperationFailure $error) {
            $response = $this->error($request, $error->errorCode, $error->status);
        } catch (\App\Domains\Support\SupportFailure $error) {
            $response = $this->error($request, $error->errorCode, $error->status);
        } catch (\App\Domains\Billing\BillingError|\App\Domains\Billing\IdempotencyException $error) {
            $status = str_ends_with($error->errorCode, '_MISSING') ? 404 : 409;
            $response = $this->error($request, $error->errorCode, $status);
        } catch (\App\Domains\Installation\InstallationAuthorityException) {
            $response = $this->error($request, 'INSTALLATION_NATIVE_NOT_READY', 503);
        } catch (\App\Exceptions\ApiException $error) {
            $response = $this->error($request, 'BUSINESS_REJECTED',
                in_array((int) $error->getCode(), [404, 409, 422, 502], true) ? (int) $error->getCode() : 409);
        } catch (AuthenticationException) {
            $response = $this->error($request, 'ACTOR_UNAUTHORIZED', 401);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $response = $this->error($request, 'NOT_FOUND', 404);
        } catch (HttpExceptionInterface $error) {
            $response = $this->error($request,
                match ($error->getStatusCode()) { 404 => 'NOT_FOUND', 403 => 'FORBIDDEN', default => 'BUSINESS_REJECTED' },
                in_array($error->getStatusCode(), [401, 403, 404, 409, 422, 429], true) ? $error->getStatusCode() : 422);
        } catch (\Illuminate\Validation\ValidationException) {
            $response = $this->error($request, 'BUSINESS_REJECTED', 422);
        } catch (Throwable) {
            // Do not log exception text: adapter failures can carry credentials.
            $response = $this->error($request, 'DEPENDENCY_UNAVAILABLE', 503);
        }
        return $response->header('Cache-Control', 'private, no-store');
    }

    private function actor(Request $request, string $domain, array $spec): ?User
    {
        if ($spec['actor'] === 'anonymous') return null;
        if (!$request->bearerToken()) throw new AuthenticationException();
        // Resolve Sanctum afresh for every operation, never a Gateway actor assertion.
        Auth::forgetGuards();
        $user = Auth::guard('sanctum')->user();
        if (!$user instanceof User || !$user->currentAccessToken() instanceof PersonalAccessToken) {
            throw new AuthenticationException();
        }
        app(InternalAudit::class)->identifiedActor($request, (int) $user->id);
        app(TxapiUser::class)->handle($request, static fn () => true);
        if ($domain === 'admin') {
            $result = app(Admin::class)->handle($request, static fn () => true);
            if ($result !== true) abort(403);
            $route = clone $request->route();
            $route->setParameter('admin_path', (string) $request->header('X-Core-Admin-Path', ''));
            $checked = clone $request;
            $checked->setRouteResolver(static fn () => $route);
            app(AdminPath::class)->handle($checked, static fn () => true);
            $permission = $spec['permission'] ?? 'identity.sessions.manage';
            if (!$user->tokenCan($permission)
                || !DB::table('tx_internal_actor_grant')->useWritePdo()->where('user_id', $user->id)
                    ->where('permission', $permission)->exists()) abort(403);
        }
        return $user;
    }

    private function dispatch(Request $request, string $operation, array $spec, array $input, ?User $actor): JsonResponse
    {
        if ($spec['handler'] !== null
            && ($spec['mode'] !== 'read' || $spec['controller'] !== null || $spec['command'] !== null
                || $actor === null && !($operation === 'catalog.offers.list' && $spec['handler'] === CatalogReadOperations::class))) {
            throw new \LogicException('INTERNAL_READ_HANDLER_INVALID');
        }
        if ($spec['command'] !== null) {
            $target = match ($operation) {
                'identity.sessions.revoke' => [SessionMutationOperations::class, 'revoke', 'workflow'],
                'identity.session.end' => [SessionMutationOperations::class, 'end', 'once'],
                'commerce.orders.create' => [CommerceMutationOperations::class, 'purchase', 'domain'],
                'commerce.orders.checkout' => [CommerceMutationOperations::class, 'checkout', 'domain'],
                'commerce.orders.cancel' => [CommerceMutationOperations::class, 'cancel', 'workflow'],
                'commerce.admin-orders.close' => [AdminOrderMutationOperations::class, 'close', 'domain'],
                'commerce.admin-orders.retry' => [AdminOrderMutationOperations::class, 'retry', 'domain'],
                'billing.recharges.create' => [CommerceMutationOperations::class, 'recharge', 'domain'],
                'billing.recharges.checkout' => [CommerceMutationOperations::class, 'rechargeCheckout', 'domain'],
                'billing.payment-attempts.query' => [CommerceMutationOperations::class, 'queryAttempt', 'domain'],
                'support.tickets.create' => [SupportMutationOperations::class, 'create', 'domain'],
                'support.tickets.reply' => [SupportMutationOperations::class, 'reply', 'domain'],
                'support.tickets.close' => [SupportMutationOperations::class, 'close', 'domain'],
                'support.admin-tickets.reply' => [SupportMutationOperations::class, 'adminReply', 'domain'],
                'support.admin-tickets.close' => [SupportMutationOperations::class, 'adminClose', 'domain'],
                'support.admin-tickets.claim' => [SupportMutationOperations::class, 'adminClaim', 'domain'],
                'support.admin-tickets.unassign' => [SupportMutationOperations::class, 'adminUnassign', 'domain'],
                default => array_merge(SupportAttachmentBindings::commands(), ContentOperationBindings::commands(), CouponOperationBindings::commands(), GiftCardOperationBindings::commands(), GiftBatchOperationBindings::commands(), CommissionOperationBindings::commands())[$operation] ?? null,
            };
            if ($actor === null || $spec['controller'] !== null || $spec['handler'] !== null
                || $target === null || [$spec['command'], $spec['method'], $spec['mode']] !== $target) {
                throw new \LogicException('INTERNAL_COMMAND_INVALID');
            }
        }
        $receipt = null;
        // Signed checkout material is a current authorization decision, not a
        // replayable receipt. Re-enter its permanent domain key on every call.
        $currentCheckout = in_array($operation, ['commerce.orders.checkout', 'billing.recharges.checkout'], true);
        // An unknown query result can advance later. A replay must consult the owner again.
        $currentOwnerResult = $currentCheckout || $operation === 'billing.payment-attempts.query'
            || ($spec['controller'] ?? null) === \App\Http\Controllers\Txapi\Admin\NativeModuleAdminController::class
            || in_array($operation, ['subscription.admin-templates.save', 'subscription.admin-templates.reset'], true)
            || ($spec['controller'] ?? null) === \App\Http\Controllers\Txapi\Admin\NativeSubscriptionAmendmentController::class
            || ($spec['controller'] ?? null) === \App\Http\Controllers\Txapi\Admin\NativeAccountAdjustmentController::class
            || ($spec['controller'] ?? null) === \App\Http\Controllers\Txapi\Admin\NativeCustomerPolicyController::class
            || ($spec['controller'] ?? null) === \App\Http\Controllers\Txapi\Admin\NativeUsageCorrectionController::class
            || in_array($spec['command'], [AdminOrderMutationOperations::class, SupportAttachmentOperations::class, SupportMutationOperations::class, ContentMutationOperations::class, CouponMutationOperations::class, GiftCardMutationOperations::class, CommissionMutationOperations::class], true);
        if (in_array($spec['mode'], ['workflow', 'domain', 'domain-once'], true)) {
            $key = $request->header('Idempotency-Key', '');
            // The same UUID is forwarded to the authoritative domain workflow.
            if (!Str::isUuid($key)) return $this->error($request, 'IDEMPOTENCY_KEY_REQUIRED', 400);
            $hash = hash('sha256', 'native-internal/v1:'.$actor->id.':'.$operation.':'.$key);
            $inputHash = OrderIdempotency::requestHash($input);
            if (in_array($operation, ['identity.accounts.batch-create', 'identity.accounts.profile.update'], true)) {
                $inputHash = hash_hmac('sha256', $inputHash, (string) config('app.key'));
            }
            $inserted = DB::table('tx_internal_request')->insertOrIgnore([
                'key_hash' => $hash, 'input_hash' => $inputHash, 'operation' => $operation,
                'actor_id' => $actor->id, 'state' => 'started', 'created_at' => time(), 'updated_at' => time(),
            ]);
            $receipt = DB::table('tx_internal_request')->useWritePdo()->where('key_hash', $hash)->first();
            if (!hash_equals($receipt->input_hash, $inputHash)) return $this->error($request, 'IDEMPOTENCY_CONFLICT', 409);
            if ($receipt->state === 'completed' && !$currentOwnerResult) {
                $data = json_decode(Crypt::decryptString($receipt->response_json), true, flags: JSON_THROW_ON_ERROR);
                $data['request_id'] = TxapiResponse::requestId($request);
                $replay = response()->json($data, $receipt->response_status);
                if (str_starts_with($operation, 'network.nodes.connections.')) {
                    $replay->header('Cache-Control', 'private, no-store, max-age=0')->header('Pragma', 'no-cache');
                }
                return $replay;
            }
            // Generic writes with unknown outcomes are never re-executed.
            // Domain workflows may safely recover using their original key.
            if (!$inserted && !in_array($spec['mode'], ['domain', 'domain-once'], true)) {
                return $this->error($request, 'IDEMPOTENCY_IN_PROGRESS', 409)->header('Retry-After', '2');
            }
        }
        try {
            if ($spec['handler'] !== null) {
                $view = app()->call([app($spec['handler']), $spec['method']], ['actor' => $actor, 'input' => $input]);
                if ($view === null) throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
                if (!$view instanceof ReadOperationResult) throw new \LogicException('INTERNAL_READ_RESULT_INVALID');
                $result = TxapiResponse::success($request, $view->data, $view->meta);
            } elseif ($spec['command'] !== null) {
                $arguments = ['actor' => $actor, 'input' => $input];
                if (in_array($spec['command'], [AdminOrderMutationOperations::class, CommerceMutationOperations::class, SupportAttachmentOperations::class, SupportMutationOperations::class, ContentMutationOperations::class, CouponMutationOperations::class, GiftCardMutationOperations::class, CommissionMutationOperations::class], true)) {
                    $arguments['idempotencyKey'] = (string) $request->header('Idempotency-Key', '');
                }
                $view = app()->call([app($spec['command']), $spec['method']], $arguments);
                if ($view === null) throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
                if (!$view instanceof MutationOperationResult) throw new \LogicException('INTERNAL_MUTATION_RESULT_INVALID');
                $result = TxapiResponse::success($request, $view->data, $view->meta, $view->status);
            } else {
                $invoke = fn () => $this->invokeController($request, $operation, $spec, $input, $actor);
                $result = ($spec['adminDatabaseWrite'] ?? false)
                    ? app(AdminMutationBoundary::class)->run($actor, $spec['permission'], $invoke)
                    : $invoke();
            }
        } catch (OperationFailure $error) {
            $result = $this->error($request, $error->errorCode, $error->status);
        }
        if (!$result instanceof JsonResponse) throw new \LogicException('INTERNAL_RESPONSE_INVALID');
        $body = $result->getData(true);
        if ($result->getStatusCode() >= 400 || isset($body['error'])) {
            $code = (string) ($body['error']['code'] ?? 'BUSINESS_REJECTED');
            if (!preg_match('/\A[A-Z][A-Z0-9_]{0,95}\z/D', $code)) $code = 'BUSINESS_REJECTED';
            $result = $this->error($request, $code, $result->getStatusCode() >= 400 ? $result->getStatusCode() : 422);
        } else {
            $data = $body['data'] ?? null;
            if (!is_array($data)) throw new \LogicException('INTERNAL_RESPONSE_INVALID');
            $project = fn (array $row): array => $this->clean(array_intersect_key($row, array_flip($spec['fields'])),
                $operation === 'subscription.links.issue', $operation !== 'settings.configuration.get',
                $operation === 'network.machines.credentials.get');
            $projected = $spec['list'] ? array_map($project, $data) : $project($data);
            $projected = OperationSchemas::output($operation, $projected);
            $meta = $body['meta'] ?? [];
            if (!is_array($meta)) throw new \LogicException('INTERNAL_METADATA_INVALID');
            if (OperationSchemas::operation($operation) === null) {
                $meta = array_intersect_key($meta, array_flip([
                    'page', 'per_page', 'total', 'last_page', 'schema', 'next_after_id',
                    'next_after_sequence', 'history_complete', 'next_before_id', 'has_unresolved', 'has_more',
                ]));
            }
            $meta = OperationSchemas::meta($operation, $meta);
            $result = TxapiResponse::success($request, $projected, $meta, $result->getStatusCode());
            if (in_array($operation, ['network.machines.credentials.get', 'network.nodes.configuration.get',
                'network.nodes.material.generate'], true) || str_starts_with($operation, 'network.nodes.connections.')) {
                $result->header('Cache-Control', 'private, no-store, max-age=0')->header('Pragma', 'no-cache');
            }
        }
        if (strlen($result->getContent()) > (int) config('internal_api.max_response_bytes')) {
            throw new \LogicException('INTERNAL_RESPONSE_LIMIT');
        }
        $domainMode = in_array($spec['mode'], ['domain', 'domain-once'], true);
        if ($receipt !== null && !($currentOwnerResult && $receipt->state === 'completed')
            && ($result->getStatusCode() < 400 || (!$domainMode && $result->getStatusCode() < 500))) {
            // A financial domain owns its transaction. This is a durable
            // workflow receipt, not a second transaction around business code.
            $stored = $result->getData(true);
            if ($currentCheckout && isset($stored['data'])) {
                // Retain non-secret attempt evidence, never a reusable signed URL.
                $stored['data'] = array_intersect_key($stored['data'], array_flip(['attempt']));
            }
            if ($spec['mode'] === 'domain-once' && isset($stored['data'])) {
                $stored['data']['link_token'] = null;
                $stored['data']['token_delivered'] = false;
            }
            $updated = DB::table('tx_internal_request')->where('key_hash', $receipt->key_hash)->where('state', 'started')->update([
                'state' => 'completed', 'response_status' => $result->getStatusCode(),
                'response_json' => Crypt::encryptString(json_encode($stored, JSON_THROW_ON_ERROR)), 'updated_at' => time(),
            ]);
            if ($updated !== 1) {
                $winner = DB::table('tx_internal_request')->useWritePdo()->where('key_hash', $receipt->key_hash)->first();
                if (!$winner || $winner->state !== 'completed') throw new \RuntimeException('INTERNAL_RECEIPT_UNAVAILABLE');
                if ($currentOwnerResult) return $result;
                $replayed = json_decode(Crypt::decryptString($winner->response_json), true, flags: JSON_THROW_ON_ERROR);
                $replayed['request_id'] = TxapiResponse::requestId($request);
                return response()->json($replayed, $winner->response_status);
            }
        }
        return $result;
    }

    private function invokeController(Request $request, string $operation, array $spec, array $input, ?User $actor): mixed
    {
        $delegated = clone $request;
        $delegated->attributes->set('native_internal_operation', $operation);
        $delegated->query->replace([]);
        $delegated->request->replace($input);
        $delegated->setJson(new InputBag($input));
        $delegated->setUserResolver(static fn () => $actor);
        $route = clone $request->route();
        $arguments = ['request' => $delegated];
        foreach ($spec['parameters'] as $name => $field) {
            $value = $input[$field];
            $arguments[$name] = $name === 'sessionId' ? (int) $value : $value;
            $route->setParameter($name, $value);
            $route->setParameter($field, $value);
            unset($input[$field]);
        }
        foreach ($spec['routeParameters'] as $name => $value) $route->setParameter($name, $value);
        $payload = isset($spec['payload']) ? $input[$spec['payload']] : $input;
        $delegated->request->replace($payload);
        $delegated->setJson(new InputBag($payload));
        $delegated->setRouteResolver(static fn () => $route);
        $formClass = match ($operation) {
            'identity.session.create', 'identity.admin-session.create' => AuthLogin::class,
            'settings.site.update' => \App\Http\Requests\Admin\ConfigSave::class,
            default => null,
        };
        if ($formClass !== null) {
            $form = $formClass::createFrom($delegated, new $formClass);
            $form->setContainer(app())->setRedirector(app('redirect'));
            $form->validateResolved();
            $arguments['request'] = $form;
        }
        return app()->call([app($spec['controller']), $spec['method']], $arguments);
    }

    private function clean(array $value, bool $allowLinkToken = false, bool $normalizeIdentifiers = true, bool $allowMachineToken = false): array
    {
        foreach ($value as $key => &$item) {
            if (is_string($key) && preg_match('/(?:password|secret|private_key|authorization|token|uuid|ciphertext)/i', $key)
                && !($allowLinkToken && in_array($key, ['link_token', 'token_delivered'], true))
                && !($allowMachineToken && $key === 'token')) {
                unset($value[$key]);
                continue;
            }
            if ($key === 'subscription' && is_array($item)) {
                $fields = OperationRegistry::all()['subscription.mine.get']['fields'];
                $item = array_intersect_key($item, array_flip($fields));
            }
            if (is_array($item)) $item = $this->clean($item, normalizeIdentifiers: $normalizeIdentifiers);
            // Typed settings include integer event flags named *_id, not database identifiers.
            elseif ($normalizeIdentifiers && is_int($item) && is_string($key)
                && ($key === 'id' || in_array($key, ['u', 'd'], true) || preg_match('/(?:_id|_bytes|_minor)\z/D', $key))) {
                $item = (string) $item;
            }
        }
        unset($item);
        return $value;
    }

    private function error(Request $request, string $code, int $status): JsonResponse
    {
        return TxapiResponse::error($request, $code, 'Core operation rejected', $status)
            ->header('Cache-Control', 'private, no-store');
    }
}
