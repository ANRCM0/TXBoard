# Contracts

This directory is the cross-component compatibility boundary.

- `http/xboard-api-contract-audit.md` records the web-to-API contract and known compatibility behavior.
- `node-protocol/README.md` records the endpoints used by TX-Node and the AccessAudit integration.

Backend route definitions remain executable source of truth. Contract changes must update the relevant frontend or node adapter in the same commit.
