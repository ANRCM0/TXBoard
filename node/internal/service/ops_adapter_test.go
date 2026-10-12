package service

import (
	"testing"

	"github.com/ANRCM0/TX-Node/internal/config"
	"github.com/ANRCM0/TX-Node/internal/model"
)

func TestServiceOpsRuntimeAdapterUsesCurrentRuntimeWithoutOwningState(t *testing.T) {
	k := &fakeKernel{running: true}
	s := newTestService(k)
	s.cfg = &config.Config{
		Kernel: config.KernelConfig{Type: "singbox"},
		Log:    config.LogConfig{Output: "/tmp/tx-node.log"},
	}
	s.lastConfig = &model.NodeSpec{Protocol: "vless"}
	s.users.Replace([]model.UserSpec{{ID: 1, UUID: "user-1"}}, computeUserHash([]model.UserSpec{{ID: 1, UUID: "user-1"}}))

	adapter := serviceOpsRuntime{service: s}

	if !adapter.CurrentAvailable() {
		t.Fatal("expected current runtime to be available")
	}
	name, running := adapter.KernelStatus()
	if name != "fake" || !running {
		t.Fatalf("unexpected kernel status: %q %v", name, running)
	}
	if got := adapter.ApplicationLogPath(); got != "/tmp/tx-node.log" {
		t.Fatalf("ApplicationLogPath = %q", got)
	}

	configSnapshot, usersSnapshot := adapter.snapshot()
	if configSnapshot != s.lastConfig || len(usersSnapshot) != 1 {
		t.Fatalf("unexpected snapshot: %#v %#v", configSnapshot, usersSnapshot)
	}
	usersSnapshot[0].UUID = "mutated"
	if s.users.Users()[0].UUID != "user-1" {
		t.Fatal("ops adapter leaked mutable Service user slice")
	}
}
