package machine

import (
 "context"
 "encoding/json"
 "time"
 "github.com/ANRCM0/TX-Node/internal/panel"
 "github.com/ANRCM0/TX-Node/internal/config"
 "github.com/ANRCM0/TX-Node/internal/controlplane"
)

type machineNodeConfig struct { Network string }
type machineRuntimeUpdateStatus struct { RequestID, Target, Status string; UpdatedAt int64; Message string }
type machineRuntimeStatus struct { Version, BuildTime, Deployment string; UpdaterAvailable bool; UpdateTargets []string; Update *machineRuntimeUpdateStatus }

type machineHandshake struct { Enabled bool; URL string }
type machineNode struct { ID int; Type string; Name string }
type machineIntervals struct { PullInterval int; PushInterval int }
type machineDiscovery struct { Nodes []machineNode; BaseConfig machineIntervals }

// machineControlPlane isolates machine transport operations from orchestration.
// The Xboard adapter preserves the existing wire format during migration.
type machineControlPlane interface {
 GetMachineNodes() (*machineDiscovery, error)
 Handshake() (*machineHandshake, error)
 NewMachineSocket(string, string, int, config.WSConfig, config.KernelConfig, func(machineEvent), func(bool)) machineSocket
 ForNode(int) machineNodeClient
 ReportMachineStatus(float64, [2]uint64, [2]uint64, [2]uint64, float64, float64, *machineRuntimeStatus) error
}

// machineNodeClient owns node REST snapshots and constructs the per-node
// control-plane adapter. The orchestrator never handles raw node REST clients.
type machineNodeClient interface {
 GetConfig() (*machineNodeConfig, error)
 ResetConfigETag()
 ControlPlane(config.KernelConfig, controlplane.PushClient, func(chan<- controlplane.StatusChange) *controlplane.NodeMailbox) controlplane.ControlPlane
}

type xboardMachineNodeClient struct { client *panel.Client }
func (x *xboardMachineNodeClient) GetConfig() (*machineNodeConfig, error) {
 cfg, err := x.client.GetConfig()
 if err != nil || cfg == nil { return nil, err }
 return &machineNodeConfig{Network: cfg.Network}, nil
}
func (x *xboardMachineNodeClient) ResetConfigETag() { x.client.ResetConfigETag() }
func (x *xboardMachineNodeClient) ControlPlane(k config.KernelConfig, push controlplane.PushClient, register func(chan<- controlplane.StatusChange) *controlplane.NodeMailbox) controlplane.ControlPlane {
 return controlplane.NewMachineXboardControlPlane(x.client, k, push, register)
}

type xboardMachineControlPlane struct { client *panel.Client }

func newXboardMachineControlPlane(client *panel.Client) machineControlPlane {
 return &xboardMachineControlPlane{client: client}
}
func (x *xboardMachineControlPlane) GetMachineNodes() (*machineDiscovery, error) {
 response, err := x.client.GetMachineNodes()
 if err != nil { return nil, err }
 result := &machineDiscovery{BaseConfig: machineIntervals{PullInterval: response.BaseConfig.PullInterval, PushInterval: response.BaseConfig.PushInterval}}
 for _, n := range response.Nodes { result.Nodes = append(result.Nodes, machineNode{ID: n.ID, Type: n.Type, Name: n.Name}) }
 return result, nil
}
func (x *xboardMachineControlPlane) Handshake() (*machineHandshake, error) {
 hs, err := x.client.Handshake()
 if err != nil { return nil, err }
 return &machineHandshake{Enabled: hs.WebSocket.Enabled, URL: hs.WebSocket.WSURL}, nil
}
func (x *xboardMachineControlPlane) NewMachineSocket(url, token string, machineID int, ws config.WSConfig, kernel config.KernelConfig, onEvent func(machineEvent), onStatus func(bool)) machineSocket {
 cfg := panel.WSClientConfig{
  StatusInterval: time.Duration(ws.StatusInterval)*time.Second,
  HandshakeTimeout: time.Duration(ws.HandshakeTimeout)*time.Second,
  BackoffInitial: time.Duration(ws.BackoffInitial)*time.Second,
  BackoffMax: time.Duration(ws.BackoffMax)*time.Second,
  MachineID: machineID,
 }
 return newXboardMachineSocket(url, token, 0, cfg,
  func(raw panel.WSEvent) { onEvent(translateMachineEvent(raw, kernel)) },
  func(status panel.WSStatusChange) { onStatus(status.Connected) }, nil)
}
func (x *xboardMachineControlPlane) ForNode(id int) machineNodeClient { return &xboardMachineNodeClient{client: x.client.ForNode(id)} }
func (x *xboardMachineControlPlane) ReportMachineStatus(cpu float64, mem, swap, disk [2]uint64, netIn, netOut float64, status *machineRuntimeStatus) error {
 var wire *panel.MachineRuntimeStatus
 if status != nil {
  wire = &panel.MachineRuntimeStatus{Version: status.Version, BuildTime: status.BuildTime, Deployment: status.Deployment, UpdaterAvailable: status.UpdaterAvailable}
  if status.Update != nil { wire.Update = &panel.MachineRuntimeUpdateStatus{RequestID: status.Update.RequestID, Target: status.Update.Target, Status: status.Update.Status, UpdatedAt: status.Update.UpdatedAt, Message: status.Update.Message} }
 }
 return x.client.ReportMachineStatus(cpu, mem, swap, disk, netIn, netOut, wire)
}

// machineEvent is the protocol-independent envelope delivered to the orchestrator.
type machineEventKind string
const (
 machineEventNode machineEventKind = "node"
 machineEventSyncNodes machineEventKind = "sync.nodes"
 machineEventRuntimeUpdate machineEventKind = "ops.machine.runtime.update"
)
type machineRuntimeUpdate struct { RequestID string; Target string }
type machineEvent struct {
 Kind machineEventKind
 NodeID int
 NodeEvent controlplane.Event
 RuntimeUpdate *machineRuntimeUpdate
 Err error
}
func translateMachineEvent(raw panel.WSEvent, kernel config.KernelConfig) machineEvent {
 event := machineEvent{Kind: machineEventNode, NodeID: raw.NodeID}
 switch raw.Type {
 case panel.WSEventSyncNodes:
  event.Kind = machineEventSyncNodes
 case panel.WSEventOpsMachineRuntimeUpdate:
  event.Kind = machineEventRuntimeUpdate
  if raw.MachineRuntimeUpdate != nil { event.RuntimeUpdate = &machineRuntimeUpdate{RequestID: raw.MachineRuntimeUpdate.RequestID, Target: raw.MachineRuntimeUpdate.Target} }
 default:
  event.NodeEvent, event.Err = controlplane.TranslateWSEvent(raw, kernel)
 }
 return event
}

// machineSocket is the shared push transport boundary. The orchestrator
// only needs lifecycle, connectivity and two outbound message types.
type machineSocket interface {
 Run(context.Context)
 IsConnected() bool
 SendDeviceReport(json.RawMessage)
 SendOpsResult(json.RawMessage)
}
type xboardMachineSocket struct { *panel.WSClient }
func newXboardMachineSocket(url, token string, nodeID int, cfg panel.WSClientConfig, onEvent func(panel.WSEvent), onStatus func(panel.WSStatusChange), onMetrics func() map[string]interface{}) machineSocket {
 return &xboardMachineSocket{WSClient: panel.NewWSClient(url, token, nodeID, cfg, onEvent, onStatus, onMetrics)}
}
func (s *xboardMachineSocket) SendDeviceReport(data json.RawMessage) { s.SendRaw(panel.WSEventReportDevices, data) }
func (s *xboardMachineSocket) SendOpsResult(data json.RawMessage) { s.SendRaw(panel.WSEventOpsResult, data) }
