<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;


class CommController extends Controller
{
    public function config(\App\Services\SiteConfigService $service)
    {
        return $this->success($service->guest());
    }

}
