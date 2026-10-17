# Core Entitlement Queries v1

> Status: merged via PR #263, 2026-10-17; exact source development CI passed. This is an in-process contract, not a new HTTP API or production qualification.

## Ownership and composition

Subscription owns entitlement rules. `EntitlementQueries` and the internal
`LockedEntitlementQueries` bind to the same scoped `SubscriptionEntitlementResolver`
through `CoreCapabilitiesServiceProvider`. Kernel contains only the required
`Clock` abstraction; `SystemClock` supplies Unix seconds. The clock is stateless
and singleton; request/work-item resolver bindings are scoped for Octane.

This is the first implemented subset of the [module ports design](core-module-ports-v1.md).
No generic invocation context, service locator registry or alternate entitlement
algorithm is introduced. Existing business mutation services retain their owners.

## Public read

```php
interface EntitlementQueries
{
    public function forAccount(EntitlementQuery $query): EntitlementView;
}
```

`EntitlementQuery` accepts a positive PHP integer `userId`; invalid input throws
`InvalidArgumentException('ENTITLEMENT_INPUT_INVALID')`. It accepts no caller clock,
subscription selector or authorization claim. Account HTTP adapters select the
authenticated account, ignoring client ownership/time hints. Existing route and
Internal API authentication, actor and operation checks remain required: this
internal read interface itself does not grant permission to read another account.

The resolver reads time from `Clock`, requires no outer SQL transaction and uses
the existing installation admission and account-first read locks. An outer
transaction throws `ENTITLEMENT_OUTER_TRANSACTION_UNSUPPORTED`. Installation
admission errors continue to propagate. Within the existing evidence read,
storage failure produces `ENTITLEMENT_STORAGE_FAILED`; damaged evidence produces
`ENTITLEMENT_EVIDENCE_INVALID`. Ordinary missing, banned, inactive, expired and
quota-exhausted states preserve their current denial reasons.

`EntitlementView` is an immutable, non-secret, closed `core-entitlement/v1`
projection with `toArray()`, `jsonSerialize()` and `isAuthorized()`. Granted output
retains the existing 23 fields; denied output retains only `schema`, `authorized`,
`reason`, `user_id`. Identifiers and byte quantities remain canonical decimal
strings; nullable limits and timestamps keep the existing scalar types. Unknown
fields, malformed scalars or reasons throw `ENTITLEMENT_VIEW_INVALID`. It detaches
caller-owned scalar references as well as ordinary array copy-on-write values.

There are no new wire fields. `consumed_bytes` already includes reservations;
consumers must not add them again. This projection is neither a subscription
credential nor a signed Node grant.

## Internal transaction participant

```php
interface LockedEntitlementQueries
{
    public function resolveLocked(int $userId, int $at): array;
}
```

This interface is restricted to existing aggregate workflows in Subscription,
Network and UsageV2. It requires an active transaction and the owning workflow's
account-first lock order. Invalid transaction/input throws
`ENTITLEMENT_TRANSACTION_REQUIRED`. Installation admission and damaged evidence
errors propagate and abort the owner transaction. It performs no network I/O.

It deliberately returns the unchanged scalar array, preserving before/after
snapshot equality used by signed authorization and delivery. Public fail-closed
diagnostic reads must not replace this stronger transaction failure behavior.

## Consumers and acceptance

The [capability map](../../docs/architecture/v2/core-capability-map.md) records all
six production call sites across five files. Account HTTP reads use the public
port; Node authorization, delivery, links and runtime usage use the locked port.
Internal API account/subscription reads continue through their existing account
adapter. Only the Subscription owner and composition root name the concrete
resolver in production code.

Candidate tests cover DTO shape/reference isolation, container scope, public
versus locked failure behavior, authenticated account selection and the existing
subscription/usage scenarios. The boundary checker prevents concrete resolver
dependencies outside owner/composition and host dependencies in Kernel/public
contracts. These source guards are not a PHP parser or authorization sandbox.
API and MySQL CI must validate this candidate before acceptance; authored tests
and local static checks do not establish runtime success.
