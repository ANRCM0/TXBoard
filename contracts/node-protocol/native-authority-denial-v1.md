# Native Node authority-denial stop v1 (Candidate)

This extends the existing native TXBoard node/v1 consumer behavior. Xboard/local providers keep their own policy. Current Node/Machine credentials and Core authorization remain authoritative. This is a basic native control correction, not a production Usage-v2/Provider or finite-offline-lease activation.

Only a completed response from the configured native handshake/config/users or machine/nodes request may classify an authority denial. Parse a complete bounded JSON error envelope with JSON Content-Type, nonempty bounded request_id, error.code and error.message strings, no successful data, no duplicate keys or trailing payload. Do not follow redirects. No error message/body/token/URL enters returned diagnostics.

| Request identity | Response | Stop scope |
| --- | --- | --- |
| Native node or machine handshake/config/users, or machine/nodes |401 + NODE_UNAUTHORIZED | Machine if the request used a Machine ID, otherwise that Node |
| Native handshake/config/users with a positive Node ID |404 + NODE_NOT_FOUND | That Node only, even with a Machine credential |
| Any other code/status, HTML/proxy404, malformed/oversized error, write/report/audit refusal, network/TLS failure or WS disconnection | Ordinary failure | No inferred authority denial |

Production relies on verified TLS to the configured Core. Existing local HTTP test compatibility is retained and does not attest protection from a network intermediary. A valid native rejection ends the affected Service runtime, closes actual listeners/connections, and cannot be ignored by backoff or superseded by queued config/user events. Restart must authenticate and obtain a fresh initial snapshot before opening a listener.

Machine rejection is propagated to the Orchestrator main loop through a bounded signal; the main loop cancels all managed Services and the shared WS, waits for their termination and returns the denial. A child never calls stopAll and waits on itself. A node-specific404 terminates only its own Service; legitimate later discovery/start requires current authentication. Transient discovery failure preserves its existing retry policy.

Stopping must preserve native-v1 traffic. First stop admission/data-plane connections, then collect the final cumulative meter and merge only its unreported delta into the existing tracker. Existing immutable pending batch ID/body stays byte-identical. Checkpoint new traffic in a separately fsynced terminal-tail slot with its own ID; do not try HTTP delivery using the denied credential. Admission of new report attempts closes before checkpoint, and an already-running sender can clear only its own matching pending slot, never the new tail. A failed checkpoint still stops the listener and exposes a fixed durability failure; it cannot certify preservation.

On a later authenticated start with a terminal tail, replay existing pending and then tail using their original IDs/body before opening a listener. Lost ACK replays the same batch, never combines old and new counters or overwrites either file. Corrupt storage, failed replay or unavailable durable storage cannot be treated as an empty queue. This recovery-before-listener gate bounds the tail queue to one pending and one terminal tail; no new traffic can accrue over an un-drained prior terminal tail. All handoff emptiness checks include both slots. In-memory standalone fixtures may exercise stop mechanics but cannot attest durable traffic recovery.

Isolated Usage-v2 retains its existing fail-stop, immutable receipt/no-implicit-resubmit and process-owner rules; native-v1 tail handling must not relabel or resubmit v2 intervals. Ordinary context cancellation and temporary outages are distinct from explicit denial. Finite offline authorization, reliable ETag snapshots, desired/applied ordering, actual custom-outbound reload, Xray strong revocation and durable applied confirmation remain subsequent P0 work.

Acceptance requires bounded status/code/path/scope/adversarial-response tests; actual async poll denial reaches Service termination; a real sing-box VLESS/VMess listener and existing stream stop; pending file hashes and new tail remain distinct through restart/lost ACK; in-flight sender/denial ordering and checkpoint/clear failures; machine two-node shutdown without deadlock and node404 scope; network/5xx remains retryable; original Node full race/vet/build and isolated-v2 regressions. Core/Workerman/native ordinary mode integration is required before describing the complete production control loop as delivered.
