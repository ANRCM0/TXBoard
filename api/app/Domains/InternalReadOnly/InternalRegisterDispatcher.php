<?php

namespace App\Domains\InternalReadOnly;

use App\Core\Http\TxapiResponse;
use App\Core\Http\TxapiErrorRenderer;
use App\Http\Controllers\Txapi\AuthController;
use App\Http\Middleware\TrimStrings;
use App\Http\Requests\Passport\AuthRegister;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;

final class InternalRegisterDispatcher
{
    public function execute(Request $outer, \stdClass $input)
    {
        // StrictJson supplied the signed raw bytes, before dotted-key global trimming.
        $native = Request::create('/txapi/auth/register', 'POST', (array) $input, [], [], [
            'REMOTE_ADDR' => $outer->server('REMOTE_ADDR'),
            'HTTP_HOST' => $outer->server('HTTP_HOST'),
        ]);
        $native->headers->set('Accept', 'application/json');
        $native->setRouteResolver($outer->getRouteResolver());
        $native->setUserResolver(static fn () => null);
        $native->setRequestLocale($outer->getLocale());
        $native->attributes->set('txapi.request_id', TxapiResponse::requestId($outer));
        $previous = app('request');
        try {
            $this->bind($native);
            return app(TrimStrings::class)->handle($native, fn ($native) =>
                app(ConvertEmptyStringsToNull::class)->handle($native, fn ($native) =>
                    app(ThrottleRequests::class)->handle($native, function ($native) use ($outer) {
                        $form = AuthRegister::createFrom($native, new AuthRegister);
                        $form->setContainer(app())->setRedirector(app(Redirector::class));
                        $this->bind($form);
                        $form->validateResolved();
                        if (!str_ends_with($form->input('email'), '@example.test')) {
                            throw new SliceFailure('RECIPIENT_NOT_ALLOWED', 400);
                        }
                        $response = app(AuthController::class)->register($form, app(InternalRegisterService::class));
                        if ($response->getStatusCode() !== 201) return $response;
                        return TxapiResponse::success($outer, $response->getData(true)['data'],
                            ['current_only' => true, 'slice' => 'internal-isolated-registration/1'], 201);
                    }, 10, 1, '')));
        } catch (ThrottleRequestsException $exception) {
            $response = TxapiErrorRenderer::render($outer, $exception);
            $retry = $exception->getHeaders()['Retry-After'] ?? null;
            if (is_numeric($retry) && (int) $retry >= 1 && (int) $retry <= 60) {
                $response->headers->set('Retry-After', (string) (int) $retry);
            }
            return $response;
        } catch (SliceFailure $failure) {
            // Laravel's inner route pipeline renders exceptions before the service
            // middleware can catch them. Keep policy failures and credential-bearing
            // hook traces inside this dispatcher, before global reporting.
            return TxapiResponse::error($outer, $failure->errorCode,
                'Internal operation rejected', $failure->status);
        } catch (\Throwable $exception) {
            return TxapiErrorRenderer::render($outer, $exception);
        } finally {
            $this->bind($previous);
        }
    }

    private function bind(Request $request): void
    {
        app()->instance('request', $request);
        Facade::clearResolvedInstance('request');
        Auth::forgetGuards();
        foreach (['api', 'sanctum'] as $name) Auth::guard($name)->setRequest($request);
    }
}
