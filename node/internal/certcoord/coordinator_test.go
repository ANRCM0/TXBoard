package certcoord

import (
	"context"
	"errors"
	"testing"

	"github.com/ANRCM0/TX-Node/internal/config"
	"github.com/ANRCM0/TX-Node/internal/kernel"
	"github.com/ANRCM0/TX-Node/internal/model"
)

type fakeBackend struct {
	startErr       error
	reconfigureErr error
	reconfigured   bool
	reconfigureCfg config.CertConfig
	changed        bool
	hasCert        bool
	renewed        bool
	tls            kernel.TLSCert
	startCalls     int
	stopCalls      int
}

func (f *fakeBackend) Start(context.Context) error {
	f.startCalls++
	return f.startErr
}
func (f *fakeBackend) Stop() { f.stopCalls++ }
func (f *fakeBackend) Reconfigure(_ context.Context, cfg config.CertConfig) (bool, error) {
	f.reconfigured = true
	f.reconfigureCfg = cfg
	return f.changed, f.reconfigureErr
}
func (f *fakeBackend) TLSCert() kernel.TLSCert { return f.tls }
func (f *fakeBackend) HasCert() bool            { return f.hasCert }
func (f *fakeBackend) CertRenewed() bool {
	value := f.renewed
	f.renewed = false
	return value
}

func TestLifecycleAndRuntimeFactsDelegateToBackend(t *testing.T) {
	backend := &fakeBackend{
		hasCert: true,
		renewed: true,
		tls: kernel.TLSCert{
			CertPEM: []byte("cert"),
			KeyPEM:  []byte("key"),
		},
	}
	coordinator := NewWithBackend(backend)

	if err := coordinator.Start(context.Background()); err != nil {
		t.Fatalf("Start: %v", err)
	}
	if backend.startCalls != 1 || !coordinator.HasCert() || !coordinator.TLSCert().HasCert() {
		t.Fatal("certificate runtime facts were not delegated")
	}
	if !coordinator.ConsumeRenewal() || coordinator.ConsumeRenewal() {
		t.Fatal("renewal flag must preserve one-shot semantics")
	}
	coordinator.Stop()
	if backend.stopCalls != 1 {
		t.Fatalf("Stop calls = %d, want 1", backend.stopCalls)
	}
}

func TestApplyModernNodeCertConfigDelegatesAndPreservesCertDir(t *testing.T) {
	backend := &fakeBackend{changed: true}
	coordinator := NewWithBackend(backend)
	current := config.CertConfig{CertDir: "/var/lib/tx-node/cert", CertMode: "none"}
	node := &model.NodeSpec{
		CertConfig: &config.CertConfig{
			CertMode: "self",
			Domain:   "node.example.com",
			CertDir:  "/untrusted/panel/path",
		},
	}

	result, err := coordinator.ApplyNodeConfig(context.Background(), current, node)
	if err != nil {
		t.Fatalf("ApplyNodeConfig: %v", err)
	}
	if !backend.reconfigured || !result.Reconfigured || !result.Changed {
		t.Fatalf("modern cert config did not delegate: %#v", result)
	}
	if backend.reconfigureCfg.CertDir != current.CertDir || result.Config.CertDir != current.CertDir {
		t.Fatalf("panel cert_dir overrode local cert dir: %#v", result.Config)
	}
	if result.Config.CertMode != "self" || result.Config.Domain != "node.example.com" {
		t.Fatalf("unexpected applied config: %#v", result.Config)
	}
}

func TestFailedModernReconfigureKeepsCurrentConfig(t *testing.T) {
	backend := &fakeBackend{reconfigureErr: errors.New("invalid cert")}
	coordinator := NewWithBackend(backend)
	current := config.CertConfig{CertDir: "/cert", CertMode: "file", Domain: "old.example.com"}

	result, err := coordinator.ApplyNodeConfig(context.Background(), current, &model.NodeSpec{
		CertConfig: &config.CertConfig{CertMode: "self", Domain: "new.example.com"},
	})
	if err == nil {
		t.Fatal("expected reconfigure error")
	}
	if result.Config.CertMode != current.CertMode || result.Config.Domain != current.Domain {
		t.Fatalf("failed reconfigure changed current config: %#v", result.Config)
	}
}

func TestLegacyFieldsRemainCompatibilityOnlyWithoutReconfigure(t *testing.T) {
	backend := &fakeBackend{}
	coordinator := NewWithBackend(backend)
	current := config.CertConfig{
		CertDir: "/cert",
		AutoTLS: false,
		Domain:  "old.example.com",
	}

	result, err := coordinator.ApplyNodeConfig(context.Background(), current, &model.NodeSpec{
		AutoTLS: true,
		Domain:  "legacy.example.com",
	})
	if err != nil {
		t.Fatalf("ApplyNodeConfig: %v", err)
	}
	if backend.reconfigured || result.Reconfigured || result.Changed {
		t.Fatalf("legacy compatibility unexpectedly reconfigured cert runtime: %#v", result)
	}
	if !result.Config.AutoTLS || result.Config.Domain != "legacy.example.com" {
		t.Fatalf("legacy config was not preserved: %#v", result.Config)
	}
}
