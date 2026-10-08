# TXBoard traffic report batch id (phase 2)

V2 `POST /api/v2/server/report` supports optional `traffic_batch_id` alongside
the existing `traffic` map. V1 `UniProxy/push` supports the same value in
`X-Traffic-Batch-ID` header. IDs must match `[A-Za-z0-9:_-]{8,80}`
and be unique per node for every new sample. The *same* ID and *unchanged*
bytes MUST be used when retrying after a timeout or lost acknowledgement.

With an ID, all accepted counters (user u/d, per-user daily stats, node u/d,
per-node daily stats) and the dedupe ledger commit as one SQL transaction.
A duplicate ID can never settle a second time. Reuse with different data
is logged as an integrity error. Ledger rows are retained indefinitely,
unless an intentional administrative retention policy is introduced.

Without a batch ID, older agents continue using the legacy best-effort
asynchronous pipeline, which **does not guarantee exactly-once accounting**.
Enable identified batches in TX-Node before claiming end-to-end delivery safety.
Acknowledge reports only after enqueue succeeds. The worker retries transient
SQL failures; Redis quota notifications are best-effort after SQL commit.

The ledger's uniqueness is `(server_id, batch_id)`. Reports are ordered by
their own immutable increment identities: an old batch arriving late is still
a unique valid increment, rather than overwriting a newer cumulative counter.
