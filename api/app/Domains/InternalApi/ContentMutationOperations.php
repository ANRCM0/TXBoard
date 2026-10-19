<?php

namespace App\Domains\InternalApi;

use App\Domains\Content\Contracts\ContentCommands;
use App\Models\User;

final class ContentMutationOperations
{
    public function __construct(private readonly ContentCommands $commands) {}

    public function noticeCreate(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->change($actor, 'notices', 'create', $input, $idempotencyKey); }
    public function noticeUpdate(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->change($actor, 'notices', 'update', $input, $idempotencyKey); }
    public function noticeVisibility(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->change($actor, 'notices', 'visibility', $input, $idempotencyKey); }
    public function noticeDelete(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->change($actor, 'notices', 'delete', $input, $idempotencyKey); }
    public function noticeSort(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->change($actor, 'notices', 'sort', $input, $idempotencyKey); }
    public function articleCreate(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->change($actor, 'knowledge', 'create', $input, $idempotencyKey); }
    public function articleUpdate(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->change($actor, 'knowledge', 'update', $input, $idempotencyKey); }
    public function articleVisibility(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->change($actor, 'knowledge', 'visibility', $input, $idempotencyKey); }
    public function articleDelete(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->change($actor, 'knowledge', 'delete', $input, $idempotencyKey); }
    public function articleSort(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->change($actor, 'knowledge', 'sort', $input, $idempotencyKey); }

    private function change(User $actor, string $kind, string $action, array $input, string $key): MutationOperationResult
    {
        try {
            return new MutationOperationResult($this->commands->execute((int) $actor->id, $kind, $action, $input, $key), $action === 'create' ? 201 : 200);
        } catch (\InvalidArgumentException $error) {
            throw new OperationFailure('CONTENT_INPUT_INVALID', 422);
        }
    }
}
