package controlplane

import (
	"testing"

	"github.com/ANRCM0/TX-Node/internal/config"
	"github.com/ANRCM0/TX-Node/internal/panel"
)

func TestXboardAuditTarget(t *testing.T) {
	cp := NewXboardControlPlane(config.PanelConfig{
		URL:      "https://panel.example.com/",
		Token:    "secret",
		NodeID:   42,
		NodeType: "vless",
	}, config.WSConfig{}, config.KernelConfig{})

	target, ok := AuditTargetOf(cp)
	if !ok {
		t.Fatal("expected Xboard control plane to expose audit target")
	}
	if target.BaseURL != "https://panel.example.com/" || target.Token != "secret" || target.NodeID != 42 || target.NodeType != "vless" {
		t.Fatalf("unexpected audit target: %+v", target)
	}
}

func TestMachineXboardAuditTarget(t *testing.T) {
	client := panel.NewClient(config.PanelConfig{
		URL:       "https://panel.example.com",
		Token:     "machine-secret",
		NodeID:    7,
		NodeType:  "trojan",
		MachineID: 9,
	})
	cp := NewMachineXboardControlPlane(client, config.KernelConfig{}, nil, nil)

	target, ok := AuditTargetOf(cp)
	if !ok {
		t.Fatal("expected machine Xboard control plane to expose audit target")
	}
	if target.NodeID != 7 || target.MachineID != 9 || target.Token != "machine-secret" {
		t.Fatalf("unexpected machine audit target: %+v", target)
	}
}

func TestLocalControlPlaneHasNoAuditTarget(t *testing.T) {
	cp := NewLocalControlPlane(&config.Config{Standalone: &config.StandaloneConfig{}})
	if target, ok := AuditTargetOf(cp); ok {
		t.Fatalf("local control plane unexpectedly exposed audit target: %+v", target)
	}
}

func TestAuditTargetRequiresRemoteIdentity(t *testing.T) {
	cp := NewXboardControlPlane(config.PanelConfig{URL: "https://panel.example.com"}, config.WSConfig{}, config.KernelConfig{})
	if _, ok := AuditTargetOf(cp); ok {
		t.Fatal("audit target should require both URL and token")
	}
}
