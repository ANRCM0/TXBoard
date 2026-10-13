# Isolated Admin network groups v1

Status: Candidate. Default off, local/testing and isolated synthetic copy only.

`admin.network-group.list` version `1` is restricted to the dedicated `admin-gateway` service and a current native Admin credential. `CORE_INTERNAL_ADMIN_NETWORK_GROUPS_ENABLED` is strictly boolean true, independently gated by the global Internal slice and isolated-copy flag. Core checks current ban, role and management path each request. No public Gateway Admin route is added.

Closed input: `admin_path`, integer `page` 1..9999 and `per_page` 1..100. The shared native network-group reader preserves descending group IDs, current User/Plan relation counts and deduplicated positive integer-cast node memberships. Native HTTP keeps its existing complete list. The new slice paginates only groups; node membership aggregation still scans current node memberships in chunks of 250. It does not count only online nodes or issue authorization grants.

Closed output: `{items,pagination}`. Each item has positive canonical native INT `id` string, `name` string, safe nonnegative integer `users_count`, `plans_count`, `server_count`, and nullable integer Unix `created_at`/`updated_at`. Pagination contains page, per_page, total and last_page. `meta={current_only:true,slice:internal-admin-network-groups/1}`. No Server/User/Plan model, credential, URL, protocol settings or subscription secret is exposed. Invalid stored DTO data or SQL failures reject the whole page with fixed 503. Technical nonce, Sanctum last-used and audit state may write; business tables remain read only.

Counts and page contents are current native reads, not a coherent transaction snapshot or proof of complete access coverage. Concurrent mutations may change totals/counts between queries/pages. Gateway validates the closed DTO, matching pagination and strictly descending unique IDs; 64 KiB response bound, 5 second deadline, no retry, role cache or native fallback remain. Audit failures reject admitted reads. Production PKI/RBAC, SC-v2 and frontend work are separate gates.
