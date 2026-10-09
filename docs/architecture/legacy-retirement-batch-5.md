# V1 retirement batch 5

## Implemented
- Official Vue invitation overview uses authenticated GET /txapi/invites.
- Invitation code generation uses POST /txapi/invites, not a state-changing GET.
- User row locking serializes active-code limit checks for concurrent requests.
- Frontend request regression tests guard against accidental legacy fallback.

## Still required before final V1 retirement
- Native commission transfer and withdrawal with financial invariant tests.
- Gift card check, redemption, history, detail and type listing with redemption concurrency tests.
- Stripe public key and Telegram login callback contract.
- Remaining official user UI calls and internal P0/purchase/traffic/feature tests.
- Legacy node, subscription, payment provider and Telegram Bot protocol ownership audit.

External consumers migrate independently. Do not delete active V1 routes or merge unverified financial changes.
