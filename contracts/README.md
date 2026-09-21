# Contracts

This directory is the compatibility boundary between TXBoard components and external clients.

- `http/xboard-api-contract-audit.md` records the web-to-API contract and known compatibility behavior.
- `node-protocol/README.md` records the panel-side protocol implemented by the independent TX-Node project.

Backend route definitions remain the executable server-side source of truth. Node-protocol changes merged here must be coordinated with compatible tests and a matching release in [PaiMonCai/TX-Node](https://github.com/PaiMonCai/TX-Node). Neither repository imports the other as a source dependency.
