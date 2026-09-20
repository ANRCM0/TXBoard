package machine

import (
	"context"
	"errors"
	"testing"
	"time"

	"github.com/PaiMonCai/TXBoard/node/internal/config"
	"github.com/PaiMonCai/TXBoard/node/internal/controlplane"
	"github.com/PaiMonCai/TXBoard/node/internal/panel"
)

// newTestOrchestrator builds an Orchestrator without touching the network.
// The panel client points at a dead port so any accidental request fails fast
// instead of hanging the test.
func newTestOrchestrator() *Orchestrator {
	return &Orchestrator{
		cfg:       &config.Config{InstanceID: "test-instance", Machine: &config.MachineConfig{MachineID: 16}},
		client:    panel.NewClient(config.PanelConfig{URL: "http://127.0.0.1:1", Token: "t", MachineID: 16}),
		nodes:     make(map[int]*nodeHandle),
		mailboxes: make(map[int]*controlplane.NodeMailbox),
		statuses:  make(map[int]chan<- controlplane.StatusChange),
		failures:  make(map[int]*nodeFailure),
	}
}

// TestUnregisterNodeRemovesHandle is the regression test for nodes that never
// came back: unregisterNode used to clean only mailboxes/statuses, leaving a
// stale handle in o.nodes that made startNode's "already running" guard skip
// the node forever. A node that failed once (bad port, panel hiccup) therefore
// stayed dead until the whole container was restarted.
func TestUnregisterNodeRemovesHandle(t *testing.T) {
	o := newTestOrchestrator()
	o.nodes[1] = &nodeHandle{done: make(chan struct{})}
	o.mailboxes[1] = controlplane.NewNodeMailbox()

	o.unregisterNode(1)

	if _, ok := o.nodes[1]; ok {
		t.Fatal("o.nodes still holds the handle: startNode would refuse to restart this node forever")
	}
	if _, ok := o.mailboxes[1]; ok {
		t.Fatal("mailbox was not cleaned up")
	}
}

func TestBackoffForCurve(t *testing.T) {
	cases := []struct {
		attempt int
		want    time.Duration
	}{
		{0, 15 * time.Second}, // guarded: invalid input clamps to the first delay
		{1, 15 * time.Second},
		{2, 30 * time.Second},
		{3, 60 * time.Second},
		{4, 120 * time.Second},
		{5, 240 * time.Second},
		{6, 300 * time.Second},   // 480s clamped to the 5m ceiling
		{50, 300 * time.Second},  // must not overflow or exceed the ceiling
		{500, 300 * time.Second}, // nor go negative
	}
	for _, c := range cases {
		if got := backoffFor(c.attempt); got != c.want {
			t.Errorf("backoffFor(%d) = %v, want %v", c.attempt, got, c.want)
		}
	}

	prev := time.Duration(0)
	for i := 1; i <= 5; i++ {
		cur := backoffFor(i)
		if cur < prev {
			t.Fatalf("backoff must be monotonic: attempt %d gave %v after %v", i, cur, prev)
		}
		prev = cur
	}
}

func TestRecordFailureParksNode(t *testing.T) {
	o := newTestOrchestrator()
	boom := errors.New("listen tcp 0.0.0.0:41251: bind: address already in use")

	o.recordFailure(7, boom, time.Now())

	f, ok := o.failures[7]
	if !ok {
		t.Fatal("failure was not recorded")
	}
	if f.count != 1 {
		t.Errorf("count = %d, want 1", f.count)
	}
	if !time.Now().Before(f.nextRetry) {
		t.Error("node should be parked in backoff right after a failure")
	}

	o.clearFailure(7)
	if _, ok := o.failures[7]; ok {
		t.Error("clearFailure did not drop the backoff state")
	}
}

// TestStableRunResetsBackoff covers the case where a node ran fine for hours
// and then died. Compounding the old attempt counter would park a previously
// healthy node for five minutes for no reason.
func TestStableRunResetsBackoff(t *testing.T) {
	o := newTestOrchestrator()
	boom := errors.New("boom")

	// Ran for 2h before failing: treated as a fresh failure.
	o.recordFailure(7, boom, time.Now().Add(-2*time.Hour))
	if got := o.failures[7].count; got != 1 {
		t.Fatalf("count after stable run = %d, want 1 (backoff must restart)", got)
	}

	// Died immediately: attempts accumulate so the delay actually grows.
	o.recordFailure(7, boom, time.Now())
	o.recordFailure(7, boom, time.Now())
	if got := o.failures[7].count; got != 3 {
		t.Fatalf("count after repeated fast failures = %d, want 3", got)
	}
	if got := backoffFor(o.failures[7].count); got != 60*time.Second {
		t.Errorf("third attempt should wait 60s, got %v", got)
	}
}

// TestStartNodeSkipsDuringBackoff makes sure a parked node is not restarted on
// every discovery tick. If the guard were removed, startNode would proceed past
// the check and leave a handle in o.nodes.
func TestStartNodeSkipsDuringBackoff(t *testing.T) {
	o := newTestOrchestrator()
	o.failures[7] = &nodeFailure{count: 1, nextRetry: time.Now().Add(time.Minute)}

	o.startNode(context.Background(), panel.MachineNode{ID: 7})

	o.mu.Lock()
	_, started := o.nodes[7]
	o.mu.Unlock()
	if started {
		t.Fatal("startNode launched a node that is still serving its backoff")
	}
}

func TestHealthRegistryAggregate(t *testing.T) {
	r := NewHealthRegistry()

	// Nothing reported yet: a node-mode deployment has no orchestrator and must
	// not be mistaken for a degraded one.
	if _, reported := r.Aggregate(); reported {
		t.Fatal("empty registry must not report, or /healthz would degrade node-mode deployments")
	}

	r.Report("a", NodeHealth{Total: 2, Failed: 0})
	agg, reported := r.Aggregate()
	if !reported || agg.Total != 2 || agg.Failed != 0 {
		t.Fatalf("got %+v reported=%v, want 2/0", agg, reported)
	}

	r.Report("b", NodeHealth{Total: 2, Failed: 2})
	agg, _ = r.Aggregate()
	if agg.Total != 4 || agg.Failed != 2 {
		t.Fatalf("aggregated %+v, want 4 total / 2 failed", agg)
	}

	r.Remove("b")
	agg, _ = r.Aggregate()
	if agg.Failed != 0 {
		t.Fatalf("after Remove, failed = %d, want 0", agg.Failed)
	}
}

// TestReportHealthPublishesDegraded wires the pieces together: a failed node
// has to show up in the process-wide registry that /healthz reads.
func TestReportHealthPublishesDegraded(t *testing.T) {
	globalHealth.Reset()
	defer globalHealth.Reset()

	o := newTestOrchestrator()
	o.setWanted(2)
	o.recordFailure(1, errors.New("bind: address already in use"), time.Now())
	o.recordFailure(93, errors.New("bind: address already in use"), time.Now())

	agg, reported := globalHealth.Aggregate()
	if !reported {
		t.Fatal("orchestrator did not publish any health snapshot")
	}
	if agg.Total != 2 || agg.Failed != 2 {
		t.Fatalf("health = %+v, want 2 total / 2 failed", agg)
	}

	// Backoff window expired: the node is eligible again, so it no longer
	// counts as failed.
	o.mu.Lock()
	o.failures[1].nextRetry = time.Now().Add(-time.Second)
	o.failures[93].nextRetry = time.Now().Add(-time.Second)
	o.mu.Unlock()
	o.reportHealth()

	if agg, _ := globalHealth.Aggregate(); agg.Failed != 0 {
		t.Fatalf("after backoff expired, failed = %d, want 0", agg.Failed)
	}
}
