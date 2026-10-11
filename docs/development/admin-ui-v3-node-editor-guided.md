# TXBoard Admin UI v3 · Node creation/editor guided settings

Reference: user-provided node editor screenshots, 2026-10-11. This is a focused follow-up to page 02/04 in [UI v3 roadmap #233](https://github.com/ANRCM0/TXBoard/issues/233).

## Scope
- Shared **create/edit node** form, not a new backend protocol implementation.
- Reuse `/api/.../protocol-definitions` schema: schema `select` fields show a searchable list with exactly the backend-provided values/labels and preserve their original typed values.
- Add curated **suggestions** for existing free-text `shadowsocks.obfs`, `shadowsocks.plugin`, `network`, `flow`, `multiplex.protocol`, and `utls.fingerprint` fields. Custom unknown/legacy values are preserved; admins can explicitly choose “自定义其他值” rather than being forced to overwrite stored configuration.
- Group form in logical sections, show selected permission/route groups as chips, remove giant gaps, fixed header/footer with advanced access and scrollable body, responsive 390px.
- Replace the `rate_time_ranges` raw JSON edit with an add/remove time-span editor (start, end, rate), still serialized to the identical backend field. Other genuinely complex optional JSON stays in an advanced section with existing invalid-JSON submission guards.
- Move the node editor's legacy CSS block out of `styles.css` into `NodeEditorModal.css` to stop global stylesheet growth; do not add another global density layer.

## Safety and backward compatibility
- No changes to backend API fields, request/response payloads, or protocol schemas.
- Existing unknown custom protocol settings must survive opening/editing/saving without a preset selection.
- Protocol change must still confirm before discarding edited credentials.
- Key generation and JSON validity safeguards stay intact.
- Do not populate credential fields from fake presets or generate keys automatically without an explicit user action.
- The selected protocol controls shown fields; defaults come from backend-managed definitions.

## Visual and functional acceptance
- [ ] CI: Web typecheck/tests/build, Documentation, Docker smoke at exact PR SHA.
- [ ] Visual: 390/768/1024/1440, light/dark, 100/125/150% zoom; keyboard, dropdown search, popover clipping and modal scroll.
- [ ] Validate creating a Shadowsocks node using selector options; edit an existing node with a custom plugin value.
- [ ] Check group/route chips and node/machine dropdowns; empty/single/many choices.
- [ ] Dynamic schedule add/change/delete; persistent saved ranges, no silent JSON truncation.
- [ ] Existing node edits, protocol switch confirmation, API validation, secrets and advanced JSON still work.
- [ ] Manual user acceptance before merging the PR.

Out of scope: new encryption algorithms, backend plugin installation, guessing defaults for credentials, or Stable/production rollout.
