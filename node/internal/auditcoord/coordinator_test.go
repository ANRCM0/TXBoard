package auditcoord

import (
	"context"
	"testing"

	"github.com/ANRCM0/TX-Node/internal/audit"
	"github.com/ANRCM0/TX-Node/internal/config"
	"github.com/ANRCM0/TX-Node/internal/controlplane"
)

type fakeControlPlane struct {
	target controlplane.AuditTarget
	ok     bool
}

func (f *fakeControlPlane) Initial(
	context.Context,
	func() map[string]interface{},
	chan<- controlplane.Event,
	chan<- controlplane.StatusChange,
) (controlplane.Bootstrap, error) {
	return controlplane.Bootstrap{}, nil
}
func (f *fakeControlPlane) Poll(context.Context) (controlplane.Snapshot, error) {
	return controlplane.Snapshot{}, nil
}
func (f *fakeControlPlane) Discover(
	context.Context,
	func() map[string]interface{},
	chan<- controlplane.Event,
	chan<- controlplane.StatusChange,
) (controlplane.PushClient, error) {
	return nil, nil
}
func (f *fakeControlPlane) Metrics() controlplane.APIMetrics { return controlplane.APIMetrics{} }
func (f *fakeControlPlane) SupportsPolling() bool            { return false }
func (f *fakeControlPlane) SupportsDiscovery() bool          { return false }
func (f *fakeControlPlane) Report(controlplane.ReportPayload) error {
	return nil
}
func (f *fakeControlPlane) ReportDevices(controlplane.PushClient, map[int][]string) {}
func (f *fakeControlPlane) SupportsReporting() bool                                  { return false }
func (f *fakeControlPlane) SupportsDeviceReports() bool                              { return false }
func (f *fakeControlPlane) AuditTarget() (controlplane.AuditTarget, bool) {
	return f.target, f.ok
}

type fakeRuntime struct {
	reporter *audit.Reporter
}

func (f *fakeRuntime) SetAuditor(reporter *audit.Reporter) {
	f.reporter = reporter
}

func TestDisabledAuditDoesNothing(t *testing.T) {
	factoryCalls := 0
	coordinator := NewWithFactory(func(audit.Config, audit.PanelAuth) *audit.Reporter {
		factoryCalls++
		return &audit.Reporter{}
	})
	runtime := &fakeRuntime{}

	status := coordinator.Attach(config.AuditConfig{}, &fakeControlPlane{}, runtime)

	if status.Enabled || status.Attached || status.Reason != ReasonDisabled {
		t.Fatalf("unexpected status: %#v", status)
	}
	if factoryCalls != 0 || runtime.reporter != nil {
		t.Fatal("disabled audit must not construct or attach reporter")
	}
}

func TestEnabledAuditRequiresControlPlaneTarget(t *testing.T) {
	factoryCalls := 0
	coordinator := NewWithFactory(func(audit.Config, audit.PanelAuth) *audit.Reporter {
		factoryCalls++
		return &audit.Reporter{}
	})

	status := coordinator.Attach(
		config.AuditConfig{Enabled: true},
		&fakeControlPlane{ok: false},
		&fakeRuntime{},
	)

	if !status.Enabled || status.Attached || status.Reason != ReasonTargetUnavailable {
		t.Fatalf("unexpected status: %#v", status)
	}
	if factoryCalls != 0 {
		t.Fatal("missing target must not construct reporter")
	}
}

func TestEnabledAuditRequiresCompatibleRuntime(t *testing.T) {
	factoryCalls := 0
	coordinator := NewWithFactory(func(audit.Config, audit.PanelAuth) *audit.Reporter {
		factoryCalls++
		return &audit.Reporter{}
	})
	cp := &fakeControlPlane{
		ok: true,
		target: controlplane.AuditTarget{
			BaseURL: "https://panel.example.com",
			Token:   "secret",
			NodeID:  7,
		},
	}

	status := coordinator.Attach(config.AuditConfig{Enabled: true}, cp, struct{}{})

	if !status.Enabled || status.Attached || status.Reason != ReasonRuntimeUnsupported {
		t.Fatalf("unexpected status: %#v", status)
	}
	if factoryCalls != 0 {
		t.Fatal("unsupported runtime must not construct reporter")
	}
}

func TestAttachMapsConfigAndPanelIdentityWithoutChangingCredentials(t *testing.T) {
	var gotCfg audit.Config
	var gotAuth audit.PanelAuth
	reporter := &audit.Reporter{}
	coordinator := NewWithFactory(func(cfg audit.Config, auth audit.PanelAuth) *audit.Reporter {
		gotCfg = cfg
		gotAuth = auth
		return reporter
	})
	runtime := &fakeRuntime{}
	cp := &fakeControlPlane{
		ok: true,
		target: controlplane.AuditTarget{
			BaseURL:   "https://panel.example.com",
			Token:     "secret-token",
			NodeID:    17,
			NodeType:  "vless",
			MachineID: 9,
		},
	}
	cfg := config.AuditConfig{
		Enabled:       true,
		ReportAll:     true,
		BatchMax:      200,
		FlushInterval: 10,
		RulesRefresh:  3,
		QueueCap:      4000,
	}

	status := coordinator.Attach(cfg, cp, runtime)

	if !status.Enabled || !status.Attached || status.Reason != ReasonAttached {
		t.Fatalf("unexpected status: %#v", status)
	}
	if runtime.reporter != reporter {
		t.Fatal("reporter was not attached to runtime capability")
	}
	if !gotCfg.Enabled || !gotCfg.ReportAll ||
		gotCfg.BatchMax != 200 ||
		gotCfg.FlushInterval != 10 ||
		gotCfg.RulesRefresh != 3 ||
		gotCfg.QueueCap != 4000 {
		t.Fatalf("audit config mapping changed: %#v", gotCfg)
	}
	if gotAuth.BaseURL != cp.target.BaseURL ||
		gotAuth.Token != cp.target.Token ||
		gotAuth.NodeID != cp.target.NodeID ||
		gotAuth.NodeType != cp.target.NodeType ||
		gotAuth.MachineID != cp.target.MachineID {
		t.Fatalf("panel identity mapping changed: %#v", gotAuth)
	}
}
