package controlplane

import (
 "fmt"
 "github.com/ANRCM0/TX-Node/internal/config"
)

// Provider identifies the protocol family backing a control-plane adapter.
type Provider string

const (
	ProviderLocal  Provider = "local"
	ProviderXboard Provider = "xboard"
	ProviderTXBoard Provider = "txboard"
)

// normalized retains compatibility with existing configurations that omit provider.
func (p Provider) normalized() Provider {
	if p == "" { return ProviderXboard }
	return p
}

// ProviderForConfig reports the control-plane provider selected by the current
// configuration. Xboard compatibility is the default remote provider; local
// standalone mode is completely panel-free.
func ProviderForConfig(cfg *config.Config) Provider {
	if cfg.IsStandalone() {
		return ProviderLocal
	}
	return Provider(cfg.Panel.Provider).normalized()
}

// NewForConfig constructs the default control plane for a node configuration.
// Core service code should depend on this factory and the ControlPlane
// interface rather than on a concrete panel protocol. A future TuneX adapter
// can therefore be added here without coupling service/kernel code to it.
func NewForConfigChecked(cfg *config.Config) (ControlPlane, error) {
	switch ProviderForConfig(cfg) {
	case ProviderLocal:
		return NewLocalControlPlane(cfg), nil
	case ProviderXboard:
		return NewXboardControlPlane(cfg.Panel, cfg.WS, cfg.Kernel), nil
	case ProviderTXBoard:
		return NewTXBoardControlPlane(cfg.Panel, cfg.WS, cfg.Kernel), nil
	default:
		// Unsupported providers are rejected during config validation. Never
		// silently connect a different provider using Xboard credentials.
		return nil, fmt.Errorf("unsupported control-plane provider %q", ProviderForConfig(cfg))
	}
}

// NewForConfig is kept for validated legacy callers. Invalid providers return nil;
// use NewForConfigChecked to obtain the diagnostic error.
func NewForConfig(cfg *config.Config) ControlPlane {
 cp, _ := NewForConfigChecked(cfg)
 return cp
}
