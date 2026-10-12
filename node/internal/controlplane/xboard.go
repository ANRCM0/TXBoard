package controlplane

import (
	"github.com/ANRCM0/TX-Node/internal/config"
	"github.com/ANRCM0/TX-Node/internal/panel"
)

// XboardControlPlane is the canonical TX-Node name for the Xboard-compatible
// control-plane adapter. PanelControlPlane remains the implementation type for
// source compatibility during the standalone transition.
type XboardControlPlane = PanelControlPlane

// NewXboardControlPlane constructs the Xboard-compatible control-plane adapter.
func NewXboardControlPlane(panelCfg config.PanelConfig, wsCfg config.WSConfig, kcfg config.KernelConfig) *XboardControlPlane {
	return NewPanelControlPlane(panelCfg, wsCfg, kcfg)
}

// MachineXboardControlPlane is the machine-mode Xboard adapter. The alias keeps
// existing integrations source-compatible while making the protocol boundary
// explicit in new TX-Node code.
type MachineXboardControlPlane = MachinePanelControlPlane

// NewMachineXboardControlPlane constructs a per-node Xboard adapter for machine
// mode. The shared websocket transport is still owned by the machine
// orchestrator.
func NewMachineXboardControlPlane(
	client *panel.Client,
	kcfg config.KernelConfig,
	push PushClient,
	registerFn func(statuses chan<- StatusChange) *NodeMailbox,
) *MachineXboardControlPlane {
	return NewMachinePanelControlPlane(client, kcfg, push, registerFn)
}

var (
	_ ControlPlane = (*XboardControlPlane)(nil)
	_ ControlPlane = (*MachineXboardControlPlane)(nil)
)
