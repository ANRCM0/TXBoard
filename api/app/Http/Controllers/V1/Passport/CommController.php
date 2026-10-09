<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Models\InviteCode;
use App\Services\Auth\EmailVerificationService;
use App\Services\CaptchaService;
use Illuminate\Http\Request;

class CommController extends Controller
{

    public function sendEmailVerify(CommSendEmailVerify $request, EmailVerificationService $issuer)
    {
        [$captchaValid, $captchaError] = app(CaptchaService::class)->verify($request);
        if (!$captchaValid) return $this->fail($captchaError);
        [$sent, $response] = $issuer->send((string) $request->input('email'));
        return $sent ? $this->success(true) : $this->fail($response);
    }

    public function pv(Request $request)
    {
        $inviteCode = InviteCode::where('code', $request->input('invite_code'))->first();
        if ($inviteCode) {
            $inviteCode->pv = $inviteCode->pv + 1;
            $inviteCode->save();
        }

        return $this->success(true);
    }

}
