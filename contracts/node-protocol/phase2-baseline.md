# Phase 2 node protocol baseline

TXBoard uses protocol definitions in `api/app/Protocols/Definitions`
as the source of truth. Xboard's UniProxy contracts were compared for
Shadowsocks, VMess, VLESS, Trojan and Hysteria, while TX-Node's
`internal/panel/types.go` is the consuming schema.

Regression coverage asserts the shared `protocol`, `server_port`,
`listen_ip` and protocol-specific security/transport fields.
REST user snapshots and WS full-sync responses use the same
`ServerService::getAvailableUsers` filtering: authorized group,
non-null plan, not banned, not expired, nonzero quota with remaining
traffic. Sorting by id makes ETags stable across equivalent queries.

Additional protocols (TUIC, AnyTLS, SOCKS, HTTP, Naive, Mieru) remain
registered via ProtocolRegistry. Cross-runtime integration should test
these against live TX-Node kernels before declaring all protocol
combination variants production certified.
