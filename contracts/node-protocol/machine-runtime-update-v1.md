# Machine Runtime Update Protocol v1

Status: **stable contract for implementation**

TXBoard is the Control Plane. TX-Node is the Agent / Data Plane. TX-Node deployment and upgrade mechanics remain owned by **TX-Node Installer**.

This contract adds a narrow Machine-level runtime update capability without introducing SSH, generic shell, Docker socket access from TXBoard, or a second deployment runtime.

## Goal

Allow an administrator to request a safe TX-Node runtime upgrade from the TXBoard Machine management surface while preserving the existing deployment boundary:

```text
TXBoard Admin
  -> Machine Admin API
  -> TXBoard machine-level WebSocket push
  -> TX-Node machine runtime
  -> local Installer update bridge
  -> existing TX-Node Installer upgrade runtime
```

The update bridge is delegation only. TX-Node does not reimplement Docker upgrade/rollback logic.

## Authoritative runtime

Deployment lifecycle remains authoritative in the public **TX-Node-Installer** repository.

TXBoard owns only:

- administrator authorization and audit;
- machine identity;
- update request orchestration;
- presentation of runtime/update state.

TX-Node owns only:

- receiving the typed Machine update request;
- validating the bounded request envelope;
- delegating it to the local Installer bridge;
- reporting runtime/update facts back to TXBoard.

## Compatibility

Machine Runtime Update v1 is additive.

Older TX-Node / Installer deployments that do not implement this contract:

- continue to connect and serve nodes normally;
- report no updater capability;
- must render as `updater_available=false` / unsupported in Admin;
- must not be treated as broken.

No existing Node Ops v1 event is changed.

## Machine status extension

`POST /api/v2/server/machine/status` may include an optional `runtime` object:

```json
{
  "runtime": {
    "version": "v2.3.0",
    "build_time": "2026-09-23T03:00:00Z",
    "deployment": "docker",
    "updater_available": true,
    "update": {
      "request_id": "mup_01J...",
      "target": "latest",
      "status": "succeeded",
      "updated_at": 1780000000,
      "message": "upgrade completed"
    }
  }
}
```

Rules:

- `runtime` is runtime-derived state, never trusted from a package manifest.
- `version` and `build_time` describe the currently running TX-Node process.
- `deployment` is an informational bounded value. v1 supports `docker`.
- `updater_available` is true only when the Installer-owned local update bridge is actually available.
- `update` is optional and reflects only the last Installer-owned update attempt visible to the current runtime.
- `message` is bounded diagnostic text and must not include credentials, image registry auth, tokens, filesystem secrets, or command output containing secrets.
- TXBoard may persist the object inside the existing Machine `load_status` JSON. No new database source of truth is required.

Allowed `update.status` values:

- `accepted`
- `running`
- `succeeded`
- `failed`
- `rolled_back`

## Update request

TXBoard sends the Machine-level WebSocket event:

```text
ops.machine.runtime.update
```

Envelope:

```json
{
  "event": "ops.machine.runtime.update",
  "data": {
    "request_id": "mup_01J...",
    "target": "latest"
  },
  "timestamp": 1780000000
}
```

v1 intentionally supports only:

```text
target = latest
```

The target is not an arbitrary image reference, URL, tag, digest, command, or filesystem path.

TXBoard generates `request_id`. It is required, bounded to 64 characters and safe for log/status correlation.

## TX-Node delegation rules

On receipt, TX-Node must:

1. verify that it is running in Machine mode;
2. verify `request_id`;
3. verify `target == latest`;
4. verify that the Installer bridge advertises itself as available;
5. write a bounded update request to the Installer-owned bridge;
6. return to normal operation until the deployment runtime replaces/restarts the container.

TX-Node must not:

- execute a caller-supplied command;
- access the Docker socket;
- construct arbitrary Docker image references;
- run a package manager;
- download arbitrary URLs;
- use SSH;
- expose a generic filesystem operation.

## Installer bridge

The Installer bridge is host-owned and installed only by TX-Node-Installer.

Recommended v1 transport is a dedicated bind-mounted control directory plus a host `systemd.path` / `systemd.service` pair.

The bridge accepts only the following logical fields:

```text
schema=1
request_id=<bounded request id>
target=latest
```

The bridge must reject:

- unknown schema;
- unknown fields that alter execution semantics;
- any target other than `latest`;
- arbitrary image references;
- arbitrary shell fragments.

The bridge delegates the actual upgrade to the existing Installer deployment runtime.

## Upgrade behavior

The Installer-owned upgrade flow must:

1. capture the currently running image ID;
2. pull the official configured TX-Node image channel;
3. recreate the TX-Node container using the existing Compose deployment;
4. verify container stability;
5. verify the configured TX-Node health endpoint when enabled;
6. mark success only after verification;
7. on failure, restore the previously running image and recreate the container;
8. report `rolled_back` if rollback succeeds;
9. report `failed` if both update and rollback fail.

v1 does not prune the previous image until the new runtime is verified.

## Admin API

The Admin implementation should expose a Machine-scoped action under the existing dynamic secure Admin path.

The action must:

- require existing administrator authentication;
- validate that the Machine exists and is active;
- require a recent Machine heartbeat;
- publish only the typed `ops.machine.runtime.update` event;
- use the normal POST request audit middleware;
- never accept a command, URL, image reference or shell fragment.

Recommended request:

```http
POST /api/v2/{secure_path}/server/machine/runtime/update
Content-Type: application/json

{
  "machine_id": 12,
  "target": "latest"
}
```

## Admin UX

The Machine Ops surface should show:

- current TX-Node version;
- updater availability;
- last update status;
- last update time;
- explicit `Update to latest` action.

The action requires an administrator confirmation because the Machine runtime will restart and all nodes hosted by that Machine can briefly disconnect.

If `updater_available=false`, Admin should show upgrade guidance instead of a working button.

## Failure and recovery

The WebSocket connection is expected to disappear during an update.

TXBoard must not interpret the disconnect itself as update failure.

Final state is derived from the runtime status reported after the new or rolled-back TX-Node reconnects.

If the Machine does not reconnect within an operator-defined observation window, Admin may show the request as unresolved/offline, but must not invent a final success/failure result.

## Security boundary

Machine Runtime Update v1 does **not** add:

- generic shell;
- SSH;
- Docker API/socket access from TXBoard or MCP;
- arbitrary URL fetch;
- arbitrary filesystem mutation;
- package installation;
- generic service control.

MCP does not receive a direct Machine update tool in v1.

A future Agent-facing update capability must still pass through:

```text
MCP
  -> Agent Ops API
  -> permission
  -> target scope
  -> approval / audit
  -> TXBoard machine lifecycle service
  -> typed Machine update operation
```

and requires an explicit contract extension.

## Non-goals

v1 does not include:

- arbitrary version selection;
- downgrade selection;
- fleet rolling-update orchestration;
- automatic update scheduling;
- update channels other than `latest`;
- OS package upgrades;
- Docker upgrades;
- kernel binary upgrades independent of TX-Node;
- legacy systemd TX-Node remote upgrade.

Those require later explicit contracts.
