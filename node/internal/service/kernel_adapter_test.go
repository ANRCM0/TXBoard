package service

import (
	"context"
	"errors"
	"testing"

	"github.com/ANRCM0/TX-Node/internal/model"
)

func TestStartKernelDelegatesAppliedStateToLifecycleCoordinator(t *testing.T) {
	k := &fakeKernel{}
	s := newTestService(k)
	cfg := &model.NodeSpec{Protocol: "vless", ServerPort: 443}
	users := []model.UserSpec{{ID: 1, UUID: "user-1"}}

	if !s.startKernel(cfg, users) {
		t.Fatal("expected kernel start success")
	}
	if k.startCalls != 1 || !k.running {
		t.Fatalf("unexpected kernel start state calls=%d running=%v", k.startCalls, k.running)
	}

	applied := s.kernelLife.Applied()
	if applied.Config != cfg || len(applied.Users) != 1 || applied.Users[0].UUID != "user-1" {
		t.Fatalf("unexpected applied state: %#v", applied)
	}
}

func TestApplyChangesUsesReloadAndFallsBackToStart(t *testing.T) {
	k := &fakeKernel{running: true, reloadErr: errors.New("reload failed")}
	s := newTestService(k)
	s.lastConfig = &model.NodeSpec{Protocol: "vless", ServerPort: 443}
	s.updateUserState([]model.UserSpec{{ID: 1, UUID: "user-1"}}, srcBootstrap)

	s.applyChanges(context.Background(), true, false)

	if k.reloadCalls != 1 {
		t.Fatalf("Reload calls = %d, want 1", k.reloadCalls)
	}
	if k.startCalls != 1 {
		t.Fatalf("Start fallback calls = %d, want 1", k.startCalls)
	}
	applied := s.kernelLife.Applied()
	if applied.Config != s.lastConfig || len(applied.Users) != 1 {
		t.Fatalf("fallback start did not record applied state: %#v", applied)
	}
}

func TestApplyChangesEmptyUsersStopsRuntimeAndClearsAppliedUsers(t *testing.T) {
	k := &fakeKernel{}
	s := newTestService(k)
	s.lastConfig = &model.NodeSpec{Protocol: "vless", ServerPort: 443}

	s.updateUserState([]model.UserSpec{{ID: 1, UUID: "user-1"}}, srcBootstrap)
	if !s.startKernel(s.lastConfig, s.users.Users()) {
		t.Fatal("initial start failed")
	}
	s.updateUserState([]model.UserSpec{}, srcWSFull)

	s.applyChanges(context.Background(), true, false)

	if k.running {
		t.Fatal("kernel should be stopped when desired users are empty")
	}
	applied := s.kernelLife.Applied()
	if applied.Config != s.lastConfig || len(applied.Users) != 0 {
		t.Fatalf("unexpected applied state after empty users: %#v", applied)
	}
}
