<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use Illuminate\Http\Request;

class NoticeController extends Controller
{
    public function fetch(Request $request)
    {
        $current = max((int) $request->input('current', 1), 1);
        $pageSize = (int) $request->input('pageSize', $request->input('page_size', 5));
        $pageSize = min(max($pageSize, 1), 100);
        $model = Notice::orderBy('sort', 'ASC')
            ->orderBy('id', 'DESC')
            ->where('show', true);
        $total = $model->count();
        $res = $model->forPage($current, $pageSize)
            ->get();
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }
}
