<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\Module\ModuleRegistry;

class ModuleController extends Controller
{
    public function __construct(
        private readonly ModuleRegistry $modules,
    ) {
    }

    public function index()
    {
        return $this->success($this->modules->snapshot()->toArray());
    }

    public function show(string $id)
    {
        $module = $this->modules->find($id);
        if (!$module) {
            return $this->fail([404000, 'Module not found']);
        }

        return $this->success($module->toArray());
    }
}
