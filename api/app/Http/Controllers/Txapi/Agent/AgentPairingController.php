<?php

namespace App\Http\Controllers\Txapi\Agent;

use App\Http\Controllers\Controller;
use App\Services\AgentOps\AgentPairingService;
use Illuminate\Http\Request;

class AgentPairingController extends Controller
{
    public function __construct(
        private readonly AgentPairingService $pairings,
    ) {
    }

    public function redeem(Request $request)
    {
        $params = $request->validate([
            'pairing_code' => ['required', 'string', 'max:64', 'regex:/^txbp_[A-Za-z0-9_-]{24}$/'],
        ]);

        try {
            $data = $this->pairings->redeem($params['pairing_code']);
        } catch (\InvalidArgumentException) {
            return $this->fail([410000, 'Pairing code is invalid, expired, or already redeemed'])
                ->header('Cache-Control', 'no-store')
                ->header('Pragma', 'no-cache');
        } catch (\Throwable) {
            return $this->fail([503000, 'Agent pairing temporarily unavailable'])
                ->header('Cache-Control', 'no-store')
                ->header('Pragma', 'no-cache');
        }

        return $this->success($data)
            ->header('Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');
    }
}
