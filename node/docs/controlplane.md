# TX-Node control-plane architecture

TX-Node core code is intentionally independent from any single panel protocol. Remote and local management systems connect to the node through the `internal/controlplane` abstraction.

## Boundary

The core service consumes only the `ControlPlane` contract (`Source` + `Sink`) and TX-native models such as `model.NodeSpec` and `model.UserSpec`.

```text
                         TX-Node core
                             |
                        ControlPlane
                 +-----------+-----------+
                 |                       |
          LocalControlPlane        remote adapters
                                         |
                              +----------+----------+
                              |                     |
                     XboardControlPlane      future adapters
                                                  (TuneX, ...)
```

Protocol-specific JSON, authentication fields, REST paths and websocket event formats must be translated inside their adapter before data reaches service or kernel code.

## Current adapters

### LocalControlPlane

Local standalone mode is panel-free. Node configuration and users are read from the local TX-Node configuration and no remote reporting is performed.

### XboardControlPlane

`XboardControlPlane` is the canonical name for the existing Xboard-compatible adapter. It preserves compatibility with Xboard REST/WebSocket APIs and translates panel objects into TX-native `NodeSpec` / `UserSpec` values.

Machine mode uses `MachineXboardControlPlane`, with the shared websocket owned by the machine orchestrator.

The historical Go names `PanelControlPlane` and `MachinePanelControlPlane` remain implementation/source-compatibility names during the migration window; new TX-Node code should use the Xboard-specific constructors.

## Factory rule

Normal node services obtain their control plane through `controlplane.NewForConfig`. Service and kernel packages should not instantiate a concrete remote adapter directly.

Machine orchestration is the exception because it owns the shared transport and therefore injects a per-node `ControlPlane` explicitly through `service.NewWithControlPlane`.

## Optional capabilities

Protocol-specific extensions that are not universal control-plane operations must be exposed as optional capability interfaces rather than by teaching core service code about a concrete panel.

The first capability is `AuditTargetProvider`. Xboard adapters implement it to expose the remote identity required by the embedded access-audit reporter; `LocalControlPlane` intentionally does not. The service resolves the capability through `controlplane.AuditTargetOf` and therefore never reads Xboard panel credentials directly.

Future adapters may implement the same capability if their backend supports the audit API, or omit it without changing core service behavior. New protocol-specific features should follow the same pattern when they do not belong in the base `ControlPlane` contract.

## Adding TuneX later

A future TuneX protocol should be implemented as a new adapter, not by adding TuneX-specific branches throughout service/kernel code. The intended path is:

```text
TuneX API / WS
      |
TuneXControlPlane
      |
TX NodeSpec / UserSpec / Event / ReportPayload
      |
TX-Node service
```

Before enabling a TuneX provider, define its authentication, bootstrap, configuration/user synchronization, reporting and push-event semantics, then add provider selection in the control-plane factory/config layer. TuneX-specific extensions should be exposed through optional capability interfaces where possible.

## Compatibility principle

Xboard support is a protocol compatibility feature, not the identity of TX-Node. Removing Xboard compatibility is not required for TX-Node to evolve independently; keeping that compatibility isolated behind an adapter is the goal.
