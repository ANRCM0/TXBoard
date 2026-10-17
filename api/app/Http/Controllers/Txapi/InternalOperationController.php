<?php

namespace App\Http\Controllers\Txapi;

use App\Domains\InternalApi\OperationExecutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InternalOperationController
{
    public function execute(Request $request, string $domain, string $operation, OperationExecutor $executor): JsonResponse
    {
        return $executor->execute($request, $domain, $operation);
    }
}
