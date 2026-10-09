<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Domains\Support\TicketWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class TicketController
{
    public function index(Request $request, TicketWorkflow $workflow): JsonResponse
    {
        $params = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        [$rows, $meta] = $workflow->list((int) Auth::guard('sanctum')->id(),
            (int) ($params['page'] ?? 1), (int) ($params['per_page'] ?? 20));
        return TxapiResponse::success($request, $rows, $meta);
    }

    public function show(Request $request, TicketWorkflow $workflow, int $ticketId): JsonResponse
    {
        $ticket = $workflow->find((int) Auth::guard('sanctum')->id(), $ticketId);
        if ($ticket === null) {
            abort(404);
        }
        return TxapiResponse::success($request, $ticket);
    }

    public function store(Request $request, TicketWorkflow $workflow): JsonResponse
    {
        $params = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'level' => ['required', 'integer', 'in:0,1,2'],
            'message' => ['required', 'string', 'max:10000'],
        ]);
        $id = $workflow->create((int) Auth::guard('sanctum')->id(),
            $params['subject'], (int) $params['level'], $params['message']);
        return TxapiResponse::success($request, ['id' => $id], status: 201);
    }

    public function reply(Request $request, TicketWorkflow $workflow, int $ticketId): JsonResponse
    {
        $params = $request->validate([
            'message' => ['required', 'string', 'max:10000'],
        ]);
        $workflow->reply((int) Auth::guard('sanctum')->id(), $ticketId, $params['message']);
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function close(Request $request, TicketWorkflow $workflow, int $ticketId): JsonResponse
    {
        $workflow->close((int) Auth::guard('sanctum')->id(), $ticketId);
        return TxapiResponse::success($request, ['ok' => true]);
    }
}
