# V1 retirement batch 5

## Native migration implemented on branch

- Invitation overview and POST code generation, with user row lock.
- Commission-to-wallet transfer, with user row lock and existing minimum checks.
- Withdrawal ticket request, preserving existing configured methods and minimum.
- Gift card check, redeem, history, detail, and type map, reusing GiftCardService.
- Stripe publishable key lookup with enabled-method filtering.
- Public invitation page-view tracking via atomic increment.

## Still required before final V1 retirement

- CI and financial invariant tests, including gift-card redemption concurrency and withdrawal repeat requests.
- Official UI Telegram callback contract and remaining V1 calls.
- Internal purchase/traffic/P0/feature tests must be ported to native routes.
- Legacy node, subscription, payment provider and Telegram Bot protocol ownership audit.
- Remove obsolete V1 routes only after internal dependencies are gone and tests pass.

External consumers migrate independently. Never merge an unverified financial migration.
