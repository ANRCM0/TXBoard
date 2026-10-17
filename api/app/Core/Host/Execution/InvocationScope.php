<?php

namespace App\Core\Host\Execution;

use App\Core\Kernel\Execution\ActorRef;
use App\Core\Kernel\Execution\CurrentInvocation;
use App\Core\Kernel\Execution\InvocationContext;
use Closure;
use Illuminate\Support\Str;

/** Scoped to the request/job; nested synchronous work restores its parent's attribution. */
final class InvocationScope implements CurrentInvocation
{
    private ?InvocationContext $value = null;
    private array $parents = [];

    public function current(): ?InvocationContext { return $this->value; }

    public function begin(string $source, ?string $id = null): string
    {
        $id ??= (string) Str::uuid();
        $next = new InvocationContext($id, $id, $source);
        $this->parents[] = $this->value;
        $this->value = $next;
        return $id;
    }

    public function end(?string $expectedId = null): void
    {
        if ($expectedId !== null && $this->value?->invocationId !== $expectedId) return;
        $this->value = array_pop($this->parents);
    }

    public function run(string $source, Closure $operation, ?string $id = null): mixed
    {
        $token = $this->begin($source, $id);
        try { return $operation(); }
        finally { $this->end($token); }
    }

    public function actor(ActorRef $actor): void
    {
        $this->replace(actor: $actor);
    }

    public function service(ActorRef $service): void
    {
        $this->replace(caller: $service, source: 'internal');
    }

    public function correlate(string $id, int $deadline): void
    {
        $this->replace(correlationId: $id, deadline: $deadline);
    }

    /** Only the host's actual admitted installation snapshot is accepted here. */
    public function installation(array $state): void
    {
        if (($state['installation_id'] ?? null) !== null && ($state['authority_epoch'] ?? null) !== null) {
            $this->replace(installationId: (string) $state['installation_id'], authorityEpoch: (string) $state['authority_epoch']);
        }
    }

    private function replace(?ActorRef $actor = null, ?ActorRef $caller = null, ?string $source = null,
        ?string $correlationId = null, ?int $deadline = null, ?string $installationId = null, ?string $authorityEpoch = null): void
    {
        $old = $this->value;
        if ($old === null) return;
        $this->value = new InvocationContext($old->invocationId, $correlationId ?? $old->correlationId,
            $source ?? $old->source, $actor ?? $old->actor, $caller ?? $old->caller,
            $installationId ?? $old->installationId, $authorityEpoch ?? $old->authorityEpoch, $deadline ?? $old->deadline);
    }
}
