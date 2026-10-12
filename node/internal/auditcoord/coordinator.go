package auditcoord

import (
	"github.com/ANRCM0/TX-Node/internal/audit"
	"github.com/ANRCM0/TX-Node/internal/config"
	"github.com/ANRCM0/TX-Node/internal/controlplane"
	"github.com/ANRCM0/TX-Node/internal/nlog"
)

const (
	ReasonDisabled           = "disabled"
	ReasonAttached           = "attached"
	ReasonTargetUnavailable  = "control_plane_target_unavailable"
	ReasonRuntimeUnsupported = "runtime_unsupported"
)

type Status struct {
	Enabled  bool
	Attached bool
	Reason   string
}

// auditTarget is the optional runtime hook implemented by sing-box.
// The coordinator deliberately depends on this narrow capability rather than a
// concrete kernel type, so Service no longer owns audit-specific kernel logic.
type auditTarget interface {
	SetAuditor(*audit.Reporter)
}

type reporterFactory func(audit.Config, audit.PanelAuth) *audit.Reporter

// Coordinator adapts the optional Access Audit capability onto a compatible
// runtime. It does not own audit rules, matching, buffering, HTTP transport, or
// reporting semantics; those stay in the existing audit.Reporter runtime.
type Coordinator struct {
	newReporter reporterFactory
}

func New() *Coordinator {
	return NewWithFactory(audit.New)
}

func NewWithFactory(factory reporterFactory) *Coordinator {
	if factory == nil {
		factory = audit.New
	}
	return &Coordinator{newReporter: factory}
}

func (c *Coordinator) Attach(
	cfg config.AuditConfig,
	cp controlplane.ControlPlane,
	runtime any,
) Status {
	if !cfg.Enabled {
		return Status{Reason: ReasonDisabled}
	}

	target, ok := controlplane.AuditTargetOf(cp)
	if !ok {
		nlog.Core().Warn("audit enabled but control plane does not expose audit target")
		return Status{
			Enabled: true,
			Reason:  ReasonTargetUnavailable,
		}
	}

	hook, ok := runtime.(auditTarget)
	if !ok || hook == nil {
		nlog.Core().Warn("audit enabled but runtime does not support audit attachment")
		return Status{
			Enabled: true,
			Reason:  ReasonRuntimeUnsupported,
		}
	}

	reporter := c.newReporter(audit.Config{
		Enabled:       cfg.Enabled,
		ReportAll:     cfg.ReportAll,
		BatchMax:      cfg.BatchMax,
		FlushInterval: cfg.FlushInterval,
		RulesRefresh:  cfg.RulesRefresh,
		QueueCap:      cfg.QueueCap,
	}, audit.PanelAuth{
		Protocol:  target.Protocol,
		BaseURL:   target.BaseURL,
		Token:     target.Token,
		NodeID:    target.NodeID,
		NodeType:  target.NodeType,
		MachineID: target.MachineID,
	})
	hook.SetAuditor(reporter)

	return Status{
		Enabled:  true,
		Attached: true,
		Reason:   ReasonAttached,
	}
}

// Attach is the default production entry point.
func Attach(
	cfg config.AuditConfig,
	cp controlplane.ControlPlane,
	runtime any,
) Status {
	return New().Attach(cfg, cp, runtime)
}
