# Admin UI v3 — Page 05 · Machine Detail

Route `/server/machine/:machineId`. Master roadmap [Issue #233](https://github.com/ANRCM0/TXBoard/issues/233).
Independent from still-unmerged #236 (Nodes), #237 (Machines), #238 (Node Detail); baseline main at `18b8f15ab51ab8405fd85730751992bc92b92a4d`.

## Per-page audit
| Existing issue | Improvement | Browser gate |
|---|---|---|
| Four tall monitoring cards and split meta boxes | Compact shared summary strip + readable machine information field grid | 390/768/1024/1440px light/dark, long values, zoom 125/150 |
| Absent last heartbeat treated as offline | Online/offline/not connected/disabled differentiated by text and dot | Unknown is not falsely “离线” |
| `Number(null)` produced fake 0% / 0 B/s | Strict numeric guards for CPU, memory, disk and network; true zero preserved | 0, null, invalid and real large values |
| GB-only resource sizes looked empty for MB usage | Automatic B/KB/MB/GB formatting | Short memory and disk usage legible |
| Bindings/Agent action empty result printed giant table | DataTable + EmptyState and retry on query failures | 0/1/many, background refresh and errors |
| Fleet counters emitted 0 while inventory/fleet failed | Show Fleet counts only after required queries resolve | Failures are not green zeroes |
| Token display lacked expiration and stale fetch isolation | Separate key-by-machine credential dialog, unmount on close/navigation, 18-second expiry and async generation guard | No cross-machine token leakage, late response ignored |
| Unstructured Agent results could expose keys | Redact secret-bearing fields before rendering short result | Diagnostics do not include token/pass/password fields |

## Functionality retained
Save channel/name/notes/activation; node binding; delete confirmation; machine history chart; agent navigation; credential copy and reset confirmation. No backend or runtime mutation contract changes.

## Required acceptance
- [ ] Exact HEAD Web CI + Docs + Docker smoke.
- [ ] Real Dev screenshot matrix with 390/768/1024/1440 widths, both themes, empty/one/many/malformed load data and table overflow.
- [ ] Keyboard and screen reader checks, modal focus management and labels.
- [ ] Sensitive credential display: no prefetch, 18s automatic unmount, switch-machine/close/disposed response, reset requires confirmation.
- [ ] User visual approval and then mark this single page complete in #233.

Draft until real browser acceptance; do not merge or publish Stable automatically.
