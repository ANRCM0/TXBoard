package controlplane

import "github.com/PaiMonCai/TXBoard/node/internal/config"

// Provider identifies the protocol family backing a control-plane adapter.
type Provider string

const (
	ProviderLocal  Provider = "local"
	ProviderXboard Provider = "xboard"
)

// ProviderForConfig reports the control-plane provider selected by the current
// configuration. Xboard compatibility is the default remote provider; local
// standalone mode is completely panel-free.
func ProviderForConfig(cfg *config.Config) Provider {
	if cfg.IsStandalone() {
		return ProviderLocal
	}
	return ProviderXboard
}

// NewForConfig constructs the default control plane for a node configuration.
// Core service code should depend on this factory and the ControlPlane
// interface rather than on a concrete panel protocol. A future TuneX adapter
// can therefore be added here without coupling service/kernel code to it.
func NewForConfig(cfg *config.Config) ControlPlane {
	switch ProviderForConfig(cfg) {
	case ProviderLocal:
		return NewLocalControlPlane(cfg)
	default:
		return NewXboardControlPlane(cfg.Panel, cfg.WS, cfg.Kernel)
	}
}
