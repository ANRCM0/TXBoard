<?php

namespace App\Http\Middleware;

use App\Core\Http\TxapiResponse;
use Closure;
use Illuminate\Http\Request;

final class TxapiRequestId
{
    public function handle(Request $request, Closure $next)
    {
        // Generate server-side IDs only; never reflect untrusted client headers.
        TxapiResponse::requestId($request);
        $response = $next($request);
        $response->headers->set('X-Request-Id', TxapiResponse::requestId($request));
        return $response;
    }
}
