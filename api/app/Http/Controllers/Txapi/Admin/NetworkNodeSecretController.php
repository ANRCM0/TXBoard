<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Services\NodeSecretGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NetworkNodeSecretController
{
    public function generate(Request $request, NodeSecretGenerator $generator): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:x25519,hex,ech'],
            'bytes' => ['sometimes', 'integer', 'min:1', 'max:64'],
            'public_name' => ['sometimes', 'string', 'max:253'],
        ]);
        // The generated payload may include private key material. Always
        // return no-store; never log it or include it in a browser error.
        return TxapiResponse::success($request, $generator->generate($data['kind'], $request))
            ->header('Cache-Control', 'no-store');
    }
}
