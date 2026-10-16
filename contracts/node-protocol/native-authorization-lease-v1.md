# Native Node authorization lease v1 (Opt-in implementation)

This contract defines a finite, signed authorization snapshot for an opted-in TX-Node. It reuses current Core account, plan, group, quota and kernel logic. It does not replace Provider credential issuance, activate isolated Usage v2, or treat `traffic_period` as an authorization lease.

The distinct [Provider-backed policy](../v2/credential-provider/native-node-delivery-v1.md)
uses non-secret Core commitments and direct Provider material retrieval with
the same finite enforcement. Its policy and closed payload are not accepted
as this legacy signed mode; native activation and usage remain gated.

## Trust and snapshot

Core signs with a dedicated Ed25519 key, separate from Laravel `APP_KEY`, Node/Machine Bearer credentials, Provider fixture keys and Usage receipt keys. The private seed is configured as a secret and is never stored in SQL, logs or DTOs. A native handshake advertises the public trust tuple `{core_instance_id,key_id,key_epoch,algorithm,public_key}` and `node_authorization_snapshot_v1` only when the feature is enabled and trust is valid. Node configuration must pin that tuple before claiming finite offline authorization. Xboard and explicitly unenrolled TXBoard nodes remain online-only and do not claim this guarantee.

The native users response includes a signed envelope:

```json
{
  "protocol_version": 1,
  "node_id": 42,
  "users": [],
  "authorization": {
    "payload": "<base64url exact UTF-8 JSON>",
    "signature": "<base64url Ed25519 signature>"
  }
}
```

The signed payload binds `protocol_version`, fixed `policy_version:"native-node-authorization/v1"`, `core_instance_id`, fixed audience `tx-node-native-authorization-v1`, target `node_id`, optional `machine_id`, decimal `revision`, `key_id`, decimal `key_epoch`, `issued_at`, `not_before`, `expires_at`, `complete:true`, and the complete deterministic users list. Machine authorization binds the child node and machine together; it is never transferable to another child. An empty users list is a complete authorization that closes affected sessions. Missing/null users, malformed fields, wrong target, invalid signature and incomplete snapshots are refused.

Core serializes per-node revision allocation in `tx_node_authorization_state`, signs every successful full snapshot and caps the lease at 120 seconds. Signed responses use `no-store`; 304 is not a renewal. An explicit 30-second authorization refresh is independent of ordinary administrator pull settings. Signing, key or SQL failure returns a bounded error and never an unsigned snapshot when signed mode is enabled.

Each enrolled node state is bound to its original Machine scope. Changing that scope refuses further signing; it does not delete or silently reset the revision ledger. Controlled scope re-enrollment and key-rotation overlap remain separate recovery gates. With multiple `instances`, enrollment and public pins must be specified inside each instance; a top-level enabled authorization block is rejected rather than silently dropped during expansion.

Installer enrollment checks the exact selected Node runtime with `tx-node --capabilities` before applying a pin. This read-only CLI emits `protocol_version:1`, `runtime:"tx-node"`, build version/commit and the `node_authorization_snapshot_v1` capability on Linux; it does not load configuration, contact Core, initialize durable state or open listeners. An old runtime lacking this entrypoint or capability is refused. Existing installations are checked by their actual immutable running image ID, rather than by a potentially newer channel tag.

## Node enforcement

Node verifies the pinned trust, signature, audience, Core identity, target scope, complete list, revision and finite time window before applying users or opening a listener. It atomically persists the accepted high-water revision, key epoch, issuance and payload digest with restrictive permissions and directory fsync. Lower revisions, key rollback, issuance rollback, state corruption or persistence failure stop admission. A same-revision identical digest may be retried without extending its deadline.

The running process converts the accepted expiry to a monotonic deadline. During network loss it may continue only until that deadline. An independent monotonic watchdog closes affected kernel sessions and refuses new connections at expiry, bypassing manager/spool persistence and observer logging. It latches admission closed before the main loop checkpoints the original traffic tail. The authorization close path does not grant the normal graceful-drain window. A restart must obtain and verify a fresh signed snapshot before opening a listener; old cached users or ETag 304 cannot authorize recovery. WebSocket user frames are hints only in signed mode; Node requests the complete signed snapshot and never applies unsigned user credentials. Machine denial stops all children, while a signed child snapshot can remove only that child.

Wall-clock rollback rejects new snapshots. After host/power loss, a normal monotonic clock cannot prove elapsed offline time on every platform; restart therefore requires online verification. Old Core/Node database or disk restores, key rollback and HA anti-rollback are outside this contract and must fail closed or use a separately verified recovery process.

Revocation changes the next complete snapshot and is bounded by the finite lease while offline. Expiry stops new admission and existing sessions. Already sampled traffic retains its original batch ID, body, observed period and durable replay identity; authorization expiry never relabels or deletes it. New traffic is never sampled under an expired snapshot.

This contract is a staged rollout. Production Provider secret delivery, key rotation overlap, Installer provisioning, full protocol coverage, hardware-backed time, HA and global migration authority remain separate gates. Passing the signed snapshot tests alone does not prove those properties.
