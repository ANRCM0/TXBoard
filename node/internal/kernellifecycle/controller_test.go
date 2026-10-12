package kernellifecycle

import (
	"context"
	"errors"
	"testing"

	"github.com/ANRCM0/TX-Node/internal/kernel"
	"github.com/ANRCM0/TX-Node/internal/model"
	"golang.org/x/time/rate"
)

type fakeKernel struct {
	running    bool
	startErr   error
	reloadErr  error
	startCalls int
	reloadCalls int
	stopCalls  int
}

func (f *fakeKernel) Name() string                      { return "fake" }
func (f *fakeKernel) Protocols() []string               { return []string{"vless"} }
func (f *fakeKernel) Capabilities() kernel.Capabilities { return kernel.Capabilities{} }
func (f *fakeKernel) Start(*model.NodeSpec, []model.UserSpec, kernel.TLSCert) error {
	f.startCalls++
	if f.startErr != nil {
		return f.startErr
	}
	f.running = true
	return nil
}
func (f *fakeKernel) Stop() {
	f.stopCalls++
	f.running = false
}
func (f *fakeKernel) IsRunning() bool { return f.running }
func (f *fakeKernel) Reload(*model.NodeSpec, []model.UserSpec, kernel.TLSCert) error {
	f.reloadCalls++
	if f.reloadErr != nil {
		return f.reloadErr
	}
	f.running = true
	return nil
}
func (f *fakeKernel) AddUsers([]model.UserSpec) (int, error)                    { return 0, nil }
func (f *fakeKernel) RemoveUsers([]model.UserSpec) (int, error)                 { return 0, nil }
func (f *fakeKernel) UpdateUsers([]model.UserSpec) (int, int, error)             { return 0, 0, nil }
func (f *fakeKernel) GetUserTraffic(context.Context) (map[int][2]int64, map[int]map[string]bool, int, error) {
	return nil, nil, 0, nil
}
func (f *fakeKernel) CloseConnection(context.Context, string) error              { return nil }
func (f *fakeKernel) CloseUserConnections(context.Context, string) error         { return nil }
func (f *fakeKernel) SetSpeedLimitFunc(func(string) *rate.Limiter)               {}
func (f *fakeKernel) SetDeviceLimitFunc(func(string) (int, bool))                {}
func (f *fakeKernel) UpdateGlobalDevices(map[int][]string)                       {}
func (f *fakeKernel) ClearGlobalDevices()                                        {}

func TestStartUpdatesAppliedOnlyAfterSuccess(t *testing.T) {
	k := &fakeKernel{}
	controller := New(k)
	cfg := &model.NodeSpec{Protocol: "vless"}
	users := []model.UserSpec{{ID: 1, UUID: "user-1"}}

	if err := controller.Start(cfg, users, kernel.TLSCert{}); err != nil {
		t.Fatalf("Start: %v", err)
	}
	applied := controller.Applied()
	if applied.Config != cfg || len(applied.Users) != 1 || applied.Users[0].UUID != "user-1" {
		t.Fatalf("unexpected applied state: %#v", applied)
	}

	users[0].UUID = "mutated"
	if controller.Applied().Users[0].UUID != "user-1" {
		t.Fatal("applied user state leaked caller slice mutation")
	}

	k.startErr = errors.New("start failed")
	if err := controller.Start(&model.NodeSpec{Protocol: "trojan"}, []model.UserSpec{{ID: 2}}, kernel.TLSCert{}); err == nil {
		t.Fatal("expected start failure")
	}
	if controller.Applied().Config != cfg {
		t.Fatal("failed start overwrote applied state")
	}
}

func TestReloadUpdatesAppliedOnlyAfterSuccess(t *testing.T) {
	k := &fakeKernel{}
	controller := New(k)
	oldCfg := &model.NodeSpec{Protocol: "vless"}
	if err := controller.Start(oldCfg, []model.UserSpec{{ID: 1}}, kernel.TLSCert{}); err != nil {
		t.Fatal(err)
	}

	newCfg := &model.NodeSpec{Protocol: "trojan"}
	if err := controller.Reload(newCfg, []model.UserSpec{{ID: 2}}, kernel.TLSCert{}); err != nil {
		t.Fatalf("Reload: %v", err)
	}
	if controller.Applied().Config != newCfg {
		t.Fatal("successful reload did not update applied config")
	}

	k.reloadErr = errors.New("reload failed")
	if err := controller.Reload(oldCfg, []model.UserSpec{{ID: 3}}, kernel.TLSCert{}); err == nil {
		t.Fatal("expected reload failure")
	}
	if controller.Applied().Config != newCfg {
		t.Fatal("failed reload overwrote applied state")
	}
}

func TestStopForNoUsersClearsAppliedUsersButKeepsConfig(t *testing.T) {
	k := &fakeKernel{}
	controller := New(k)
	cfg := &model.NodeSpec{Protocol: "vless"}
	if err := controller.Start(cfg, []model.UserSpec{{ID: 1}}, kernel.TLSCert{}); err != nil {
		t.Fatal(err)
	}

	controller.StopForNoUsers()
	applied := controller.Applied()
	if applied.Config != cfg || len(applied.Users) != 0 {
		t.Fatalf("unexpected applied state after empty users: %#v", applied)
	}
	if k.stopCalls != 1 || k.running {
		t.Fatalf("kernel stop state calls=%d running=%v", k.stopCalls, k.running)
	}
}

func TestStopPreservesAppliedState(t *testing.T) {
	k := &fakeKernel{}
	controller := New(k)
	cfg := &model.NodeSpec{Protocol: "vless"}
	if err := controller.Start(cfg, []model.UserSpec{{ID: 1}}, kernel.TLSCert{}); err != nil {
		t.Fatal(err)
	}

	controller.Stop()
	if controller.Applied().Config != cfg || len(controller.Applied().Users) != 1 {
		t.Fatal("shutdown stop should preserve applied bookkeeping")
	}
}
