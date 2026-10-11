# Admin UI v3 · Page 03 — Machine Management

Page route: `/server/machine`. Parent roadmap: [Issue #233](https://github.com/ANRCM0/TXBoard/issues/233). Base `main` SHA `18b8f15ab51ab8405fd85730751992bc92b92a4d`.

## Page-only audit and changes
| Problem | Change | Browser acceptance |
|---|---|---|
| Five elevated overview cards with sparse values | One content-sized five-metric summary strip | No isolated cards at 390/768/1024/1440; readable numbers |
| CPU / Memory / Disk separate undersized columns | One grouped resource load column with 3 compact values and mini bars | Unknown is “—”, real 0 stays 0%, alert thresholds legible |
| No heartbeat identified as offline | Separate “未连接” for no heartbeat; keep “离线” for stale and “已停用” for disabled | Different real states clearly visible in light/dark |
| Wide server list truncates useful names/metrics on small screens | Accessible horizontally scrollable table; long notes truncated | All controls reachable at mobile widths |
| Search and refresh controls misaligned; no clear list count | 40px search, clear button, sync feedback and count | Filter zero, initial zero, many rows, refresh/error |
| Machine edits and operations easy to confuse | Clear ARIA labels and retained quick ops/edit/delete controls | Open/close machine drawer; editing and confirmation still work |

## Out-of-scope and security preservation
- Keep MachineOpsDrawer tabs, runtime updater, token reveal/rotation and 18-second credential expiry **unchanged**.
- MachineNodeBinding behavior, permissions, machine API contracts and backend unchanged.
- No modification to Node Management PR #236; independent PR from main.
- New scope-specific `MachinesPage.css` is imported by this page, not appended to global style overrides.

## Acceptance gates
- [ ] Current SHA Web CI + Docs + Docker smoke
- [ ] Real authenticated Dev screenshot matrix: 390 / 768 / 1024 / 1440px, light/dark, 100/125/150% zoom
- [ ] 0/1/many rows, filtered-empty, error and background refetch, long notes and status conditions
- [ ] Verify overflow/scroll, action controls, editor, node binding and time-limited credentials on disposable Dev data
- [ ] Manual approval, then merge; do not mark #233 page done on CI alone

Do not deploy Stable or restart production containers automatically.
