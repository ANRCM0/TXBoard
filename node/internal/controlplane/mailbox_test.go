package controlplane

import (
	"strconv"
	"testing"

	"github.com/ANRCM0/TX-Node/internal/model"
)

func TestNodeMailboxLatestStateAndNotifyCollapse(t *testing.T) {
	mb := NewNodeMailbox()
	cfg1 := &model.NodeSpec{Protocol: "vmess", ServerPort: 10010}
	cfg2 := &model.NodeSpec{Protocol: "vless", ServerPort: 10020}

	mb.Apply(Event{Type: EventSyncConfig, Config: cfg1})
	mb.Apply(Event{Type: EventSyncConfig, Config: cfg2})

	select {
	case <-mb.NotifyCh():
	default:
		t.Fatal("expected collapsed notify signal")
	}

	mb.MarkReady()
	state := mb.DrainIfReady()
	if !state.HasConfig || state.Config == nil {
		t.Fatal("expected config state")
	}
	if state.Config.Protocol != "vless" || state.Config.ServerPort != 10020 {
		t.Fatalf("unexpected config: %+v", state.Config)
	}
}

func TestNodeMailboxFullUsersOverwriteAndDeltaBeforeBaseline(t *testing.T) {
	mb := NewNodeMailbox()
	mb.Apply(Event{
		Type:        EventSyncUserDelta,
		DeltaAction: "add",
		DeltaUsers:  []model.UserSpec{{ID: 1, UUID: "u1"}},
	})
	mb.MarkReady()
	state := mb.DrainIfReady()
	if !state.NeedsReconcile {
		t.Fatal("expected reconcile when delta arrives before baseline")
	}

	mb.Apply(Event{Type: EventSyncUsers, Users: []model.UserSpec{{ID: 1, UUID: "u1"}}})
	mb.Apply(Event{Type: EventSyncUsers, Users: []model.UserSpec{{ID: 2, UUID: "u2"}}})
	state = mb.DrainIfReady()
	if !state.HasUsers {
		t.Fatal("expected users snapshot")
	}
	if len(state.Users) != 1 || state.Users[0].ID != 2 {
		t.Fatalf("unexpected users: %+v", state.Users)
	}
}

func TestNodeMailboxDevicesLatestWins(t *testing.T) {
	mb := NewNodeMailbox()
	mb.Apply(Event{Type: EventSyncDevices, DeviceUsers: map[int][]string{1: {"1.1.1.1"}}})
	mb.Apply(Event{Type: EventSyncDevices, DeviceUsers: map[int][]string{1: {"2.2.2.2"}}})
	mb.MarkReady()
	state := mb.DrainIfReady()
	if !state.HasDevices {
		t.Fatal("expected devices state")
	}
	if got := state.DeviceUsers[1][0]; got != "2.2.2.2" {
		t.Fatalf("unexpected device state: %v", state.DeviceUsers)
	}
}

func TestNodeMailboxReconcileNotifies(t *testing.T) {
	mb := NewNodeMailbox()
	mb.MarkReady()

	// Drain the MarkReady notification
	select {
	case <-mb.NotifyCh():
	default:
	}

	// Delta without baseline should trigger reconcile AND notify
	mb.Apply(Event{
		Type:        EventSyncUserDelta,
		DeltaAction: "add",
		DeltaUsers:  []model.UserSpec{{ID: 1, UUID: "u1"}},
	})

	select {
	case <-mb.NotifyCh():
	default:
		t.Fatal("expected notify on reconcile path")
	}

	state := mb.DrainIfReady()
	if !state.NeedsReconcile {
		t.Fatal("expected NeedsReconcile")
	}
}

func TestNodeMailboxSeedBaselineEnablesDelta(t *testing.T) {
	mb := NewNodeMailbox()
	mb.SeedBaseline([]model.UserSpec{{ID: 1, UUID: "u1"}, {ID: 2, UUID: "u2"}}, nil)
	mb.MarkReady()

	// Drain MarkReady notify
	select {
	case <-mb.NotifyCh():
	default:
	}

	// Delta should now apply incrementally, not trigger reconcile
	mb.Apply(Event{
		Type:        EventSyncUserDelta,
		DeltaAction: "add",
		DeltaUsers:  []model.UserSpec{{ID: 3, UUID: "u3"}},
	})

	select {
	case <-mb.NotifyCh():
	default:
		t.Fatal("expected notify after delta")
	}

	state := mb.DrainIfReady()
	if state.NeedsReconcile {
		t.Fatal("should not need reconcile after seeded baseline")
	}
	if !state.HasUsers {
		t.Fatal("expected users")
	}
	if len(state.Users) != 3 {
		t.Fatalf("expected 3 users, got %d: %+v", len(state.Users), state.Users)
	}
}

func TestNodeMailboxDeltaFailedApplyNotifies(t *testing.T) {
	mb := NewNodeMailbox()
	mb.SeedBaseline([]model.UserSpec{{ID: 1, UUID: "u1"}}, nil)
	mb.MarkReady()

	// Drain MarkReady notify
	select {
	case <-mb.NotifyCh():
	default:
	}

	// Invalid action should trigger reconcile and still notify
	mb.Apply(Event{
		Type:        EventSyncUserDelta,
		DeltaAction: "invalid_action",
		DeltaUsers:  []model.UserSpec{{ID: 2, UUID: "u2"}},
	})

	select {
	case <-mb.NotifyCh():
	default:
		t.Fatal("expected notify on failed delta apply")
	}

	state := mb.DrainIfReady()
	if !state.NeedsReconcile {
		t.Fatal("expected NeedsReconcile for invalid delta action")
	}
}


func TestNodeMailboxPreservesOpsRequestsInOrder(t *testing.T) {
	mb := NewNodeMailbox()
	mb.Apply(Event{
		Type: EventOpsRequest,
		OpsRequest: &OpsRequest{RequestID: "ops_1", Operation: "ops.kernel.status", Args: map[string]interface{}{}},
	})
	mb.Apply(Event{
		Type: EventOpsRequest,
		OpsRequest: &OpsRequest{RequestID: "ops_2", Operation: "ops.kernel.restart", Args: map[string]interface{}{}},
	})
	mb.MarkReady()

	state := mb.DrainIfReady()
	if len(state.OpsRequests) != 2 {
		t.Fatalf("expected 2 ops requests, got %d", len(state.OpsRequests))
	}
	if state.OpsRequests[0].RequestID != "ops_1" || state.OpsRequests[1].RequestID != "ops_2" {
		t.Fatalf("unexpected ops order: %+v", state.OpsRequests)
	}

	// Draining consumes operation requests exactly once.
	state = mb.DrainIfReady()
	if len(state.OpsRequests) != 0 {
		t.Fatalf("expected ops queue to be empty after drain, got %+v", state.OpsRequests)
	}
}

func TestNodeMailboxOpsOverflowRejectsNewestWithoutAffectingSnapshots(t *testing.T) {
	mb := NewNodeMailbox()
	mb.Apply(Event{Type: EventSyncConfig, Config: &model.NodeSpec{Protocol: "vless", ServerPort: 443}})
	for i := 0; i < maxPendingOpsRequests; i++ {
		if !mb.Apply(Event{Type: EventOpsRequest, OpsRequest: &OpsRequest{
			RequestID: strconv.Itoa(i), Operation: "ops.kernel.status",
		}}) {
			t.Fatalf("operation %d rejected before reaching the limit", i)
		}
	}
	if mb.Apply(Event{Type: EventOpsRequest, OpsRequest: &OpsRequest{
		RequestID: "overflow", Operation: "ops.kernel.restart",
	}}) {
		t.Fatal("overflow must be rejected instead of allocating an unbounded queue")
	}
	mb.MarkReady()
	state := mb.DrainIfReady()
	if !state.HasConfig || state.Config.Protocol != "vless" {
		t.Fatal("ops saturation must not evict coalesced config state")
	}
	if len(state.OpsRequests) != maxPendingOpsRequests {
		t.Fatalf("queued operations = %d, want %d", len(state.OpsRequests), maxPendingOpsRequests)
	}
	if state.OpsRequests[0].RequestID != "0" || state.OpsRequests[len(state.OpsRequests)-1].RequestID != strconv.Itoa(maxPendingOpsRequests-1) {
		t.Fatal("queue did not preserve accepted FIFO requests")
	}
	if !mb.Apply(Event{Type: EventOpsRequest, OpsRequest: &OpsRequest{RequestID: "retry"}}) {
		t.Fatal("queue must accept new operations after drain")
	}
	if next := mb.DrainIfReady(); len(next.OpsRequests) != 1 || next.OpsRequests[0].RequestID != "retry" {
		t.Fatalf("unexpected queue after retry: %+v", next.OpsRequests)
	}
}
