package pushsync

import (
	"context"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/ANRCM0/TX-Node/internal/controlplane"
)

type fakePush struct {
	connected atomic.Bool
	started   chan struct{}
	stopped   chan struct{}
	onceStart sync.Once
	onceStop  sync.Once
}

func newFakePush(connected bool) *fakePush {
	p := &fakePush{
		started: make(chan struct{}),
		stopped: make(chan struct{}),
	}
	p.connected.Store(connected)
	return p
}

func (p *fakePush) Run(ctx context.Context) {
	p.onceStart.Do(func() { close(p.started) })
	<-ctx.Done()
	p.onceStop.Do(func() { close(p.stopped) })
}

func (p *fakePush) IsConnected() bool {
	return p.connected.Load()
}

func (p *fakePush) SendDeviceReport(map[int][]string) {}

type fakeSource struct {
	mu                sync.Mutex
	supportsDiscovery bool
	supportsPolling   bool
	discoverCalls     int
	discoverPush      controlplane.PushClient
	discoverErr       error
}

func (f *fakeSource) Initial(
	context.Context,
	func() map[string]interface{},
	chan<- controlplane.Event,
	chan<- controlplane.StatusChange,
) (controlplane.Bootstrap, error) {
	return controlplane.Bootstrap{}, nil
}

func (f *fakeSource) Poll(context.Context) (controlplane.Snapshot, error) {
	return controlplane.Snapshot{}, nil
}

func (f *fakeSource) Discover(
	context.Context,
	func() map[string]interface{},
	chan<- controlplane.Event,
	chan<- controlplane.StatusChange,
) (controlplane.PushClient, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.discoverCalls++
	return f.discoverPush, f.discoverErr
}

func (f *fakeSource) Metrics() controlplane.APIMetrics { return controlplane.APIMetrics{} }
func (f *fakeSource) SupportsPolling() bool            { return f.supportsPolling }
func (f *fakeSource) SupportsDiscovery() bool          { return f.supportsDiscovery }

func (f *fakeSource) calls() int {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.discoverCalls
}

func TestBootstrapClientLifecycle(t *testing.T) {
	source := &fakeSource{}
	controller := New(source)
	push := newFakePush(true)

	controller.SetBootstrapClient(push)
	if enabled, connected := controller.State(); !enabled || !connected {
		t.Fatalf("unexpected state enabled=%v connected=%v", enabled, connected)
	}
	if !controller.Start(context.Background()) {
		t.Fatal("expected bootstrap client to start")
	}
	if controller.Start(context.Background()) {
		t.Fatal("client must not start twice")
	}

	select {
	case <-push.started:
	case <-time.After(time.Second):
		t.Fatal("push client did not start")
	}

	controller.Stop()
	select {
	case <-push.stopped:
	case <-time.After(time.Second):
		t.Fatal("push client did not stop")
	}
}

func TestObserveStatusTracksDisconnectForDiscovery(t *testing.T) {
	source := &fakeSource{
		supportsDiscovery: true,
		discoverPush:      newFakePush(true),
	}
	controller := New(source)
	controller.SetBootstrapClient(newFakePush(false))

	obs := controller.ObserveStatus(controlplane.StatusChange{
		Connected:   false,
		NeedsResync: true,
	})
	if obs.Connected || !obs.NeedsResync {
		t.Fatalf("unexpected observation: %#v", obs)
	}

	if controller.needsDiscovery(time.Now(), time.Hour) {
		t.Fatal("fresh disconnect must not trigger long-disconnect discovery")
	}
	if !controller.needsDiscovery(time.Now().Add(2*time.Hour), time.Hour) {
		t.Fatal("stale disconnect should trigger discovery")
	}

	controller.ObserveStatus(controlplane.StatusChange{Connected: true})
	if controller.needsDiscovery(time.Now().Add(2*time.Hour), time.Hour) {
		t.Fatal("reconnect must clear disconnect timestamp")
	}
}

func TestDiscoverEnablesClientAndRequestsPoll(t *testing.T) {
	push := newFakePush(true)
	source := &fakeSource{
		supportsDiscovery: true,
		supportsPolling:   true,
		discoverPush:      push,
	}
	controller := New(source)

	result, err := controller.Discover(context.Background(), func() map[string]interface{} {
		return map[string]interface{}{"kernel_status": true}
	}, 10*time.Minute)
	if err != nil {
		t.Fatalf("Discover: %v", err)
	}
	if !result.Checked || !result.NeedsPoll || result.Transition != TransitionEnabled {
		t.Fatalf("unexpected result: %#v", result)
	}

	select {
	case <-push.started:
	case <-time.After(time.Second):
		t.Fatal("discovered push client did not start")
	}

	if controller.Client() != push {
		t.Fatal("discovered client was not installed")
	}
	controller.Stop()
}

func TestDiscoverDisablesExistingClient(t *testing.T) {
	source := &fakeSource{
		supportsDiscovery: true,
		supportsPolling:   true,
		discoverPush:      nil,
	}
	controller := New(source)
	push := newFakePush(false)
	controller.SetBootstrapClient(push)
	if !controller.Start(context.Background()) {
		t.Fatal("expected bootstrap push to start")
	}
	<-push.started

	controller.ObserveStatus(controlplane.StatusChange{Connected: false})

	// Force the disconnect to be considered stale without sleeping.
	controller.mu.Lock()
	controller.disconnectedAt = time.Now().Add(-11 * time.Minute)
	controller.mu.Unlock()

	result, err := controller.Discover(context.Background(), nil, 10*time.Minute)
	if err != nil {
		t.Fatalf("Discover: %v", err)
	}
	if result.Transition != TransitionDisabled || !result.NeedsPoll {
		t.Fatalf("unexpected result: %#v", result)
	}
	if controller.Client() != nil {
		t.Fatal("disabled discovery must clear client")
	}

	select {
	case <-push.stopped:
	case <-time.After(time.Second):
		t.Fatal("old push client was not cancelled")
	}
}

func TestDiscoverSkipsUnsupportedSource(t *testing.T) {
	source := &fakeSource{
		supportsDiscovery: false,
		discoverPush:      newFakePush(true),
	}
	controller := New(source)

	result, err := controller.Discover(context.Background(), nil, 10*time.Minute)
	if err != nil {
		t.Fatalf("Discover: %v", err)
	}
	if result.Checked || source.calls() != 0 {
		t.Fatalf("unsupported source should not discover: %#v calls=%d", result, source.calls())
	}
}

func TestEventAndStatusChannelsAreStable(t *testing.T) {
	controller := New(&fakeSource{})

	event := controlplane.Event{}
	controller.EventSink() <- event
	select {
	case <-controller.Events():
	case <-time.After(time.Second):
		t.Fatal("event channel not wired")
	}

	status := controlplane.StatusChange{Connected: true}
	controller.StatusSink() <- status
	select {
	case got := <-controller.Statuses():
		if !got.Connected {
			t.Fatalf("unexpected status: %#v", got)
		}
	case <-time.After(time.Second):
		t.Fatal("status channel not wired")
	}
}
