<?php

namespace App\Domains\InternalApi;

use App\Domains\Support\Contracts\TicketAttachments;
use App\Models\User;

final class SupportAttachmentOperations
{
    public function __construct(private readonly TicketAttachments $attachments) {}

    public function userBegin(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->attachments->begin((int) $actor->id, $input, $idempotencyKey, false));
    }

    public function userAppend(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->attachments->append((int) $actor->id, $input, $idempotencyKey, false));
    }

    public function userFinish(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->attachments->finish((int) $actor->id, $input, $idempotencyKey, false));
    }

    public function userCancel(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->attachments->cancel((int) $actor->id, $input, $idempotencyKey, false));
    }

    public function userGet(User $actor, array $input): ?ReadOperationResult
    {
        $result = $this->attachments->get((int) $actor->id, $input['attachment_id'], false);
        return $result === null ? null : new ReadOperationResult($result);
    }

    public function userDownload(User $actor, array $input): ?ReadOperationResult
    {
        $result = $this->attachments->download((int) $actor->id, $input, false);
        return $result === null ? null : new ReadOperationResult($result);
    }

    public function adminBegin(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->attachments->begin((int) $actor->id, $input, $idempotencyKey, true));
    }

    public function adminAppend(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->attachments->append((int) $actor->id, $input, $idempotencyKey, true));
    }

    public function adminFinish(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->attachments->finish((int) $actor->id, $input, $idempotencyKey, true));
    }

    public function adminCancel(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->attachments->cancel((int) $actor->id, $input, $idempotencyKey, true));
    }

    public function adminGet(User $actor, array $input): ?ReadOperationResult
    {
        $result = $this->attachments->get((int) $actor->id, $input['attachment_id'], true);
        return $result === null ? null : new ReadOperationResult($result);
    }

    public function adminDownload(User $actor, array $input): ?ReadOperationResult
    {
        $result = $this->attachments->download((int) $actor->id, $input, true);
        return $result === null ? null : new ReadOperationResult($result);
    }
}
