package reporting

import (
	"errors"
	"sync"
	"testing"
	"time"

	"github.com/ANRCM0/TX-Node/internal/controlplane"
)

type fakeSink struct {
	mu        sync.Mutex
	supported bool
	calls     int
	err       error
	batchIDs  []string
	started   chan struct{}
	release   chan struct{}
}

func (f *fakeSink) Report(payload controlplane.ReportPayload) error {
	f.mu.Lock()
	f.calls++
	f.batchIDs = append(f.batchIDs, payload.BatchID)
	started := f.started
	release := f.release
	err := f.err
	f.mu.Unlock()

	if started != nil {
		select {
		case started <- struct{}{}:
		default:
		}
	}
	if release != nil {
		<-release
	}
	return err
}
func (f *fakeSink) ReportDevices(controlplane.PushClient, map[int][]string) {}
func (f *fakeSink) SupportsReporting() bool {
	return f.supported
}
func (f *fakeSink) SupportsDeviceReports() bool { return false }
func (f *fakeSink) callCount() int {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.calls
}

func TestPushAsyncPreventsOverlapAndPreparesOnlyOnce(t *testing.T) {
	started := make(chan struct{}, 1)
	release := make(chan struct{})
	sink := &fakeSink{supported: true, started: started, release: release}
	controller := New(sink)

	prepares := 0
	prepare := func() Batch {
		prepares++
		return Batch{TrafficCount: 2, OnlineCount: 1}
	}

	if !controller.PushAsync(prepare, nil, nil) {
		t.Fatal("first report should start")
	}
	<-started

	if controller.PushAsync(prepare, nil, nil) {
		t.Fatal("overlapping report must be rejected")
	}
	if prepares != 1 {
		t.Fatalf("prepare calls = %d, want 1", prepares)
	}

	close(release)
	deadline := time.Now().Add(time.Second)
	for controller.Active() && time.Now().Before(deadline) {
		time.Sleep(time.Millisecond)
	}
	if controller.Active() {
		t.Fatal("report did not finish")
	}
}

func TestPushAsyncFailureInvokesRestoreAndBackoff(t *testing.T) {
	sink := &fakeSink{supported: true, err: errors.New("panel unavailable")}
	controller := New(sink)

	failures := 0
	prepare := func() Batch {
		return Batch{
			Payload: controlplane.ReportPayload{
				Traffic: map[int][2]int64{1: [2]int64{10, 20}},
				Alive:   map[int][]string{1: []string{"203.0.113.1"}},
			},
			TrafficCount: 1,
		}
	}

	if !controller.PushAsync(prepare, func(batch Batch, err error) {
		failures++
		if len(batch.Payload.Traffic) != 0 || err == nil {
			t.Fatalf("unexpected failure callback: %#v %v", batch, err)
		}
	}, nil) {
		t.Fatal("first report should start")
	}

	deadline := time.Now().Add(time.Second)
	for controller.Active() && time.Now().Before(deadline) {
		time.Sleep(time.Millisecond)
	}
	if failures != 1 {
		t.Fatalf("failure callbacks = %d, want 1", failures)
	}

	// First retry is consumed by bounded backoff and must not invoke Prepare.
	prepares := 0
	if controller.PushAsync(func() Batch {
		prepares++
		return Batch{}
	}, nil, nil) {
		t.Fatal("first retry should be skipped by backoff")
	}
	if prepares != 0 {
		t.Fatalf("skipped retry prepared %d batches", prepares)
	}
}

func TestPushAsyncSuccessClearsBackoff(t *testing.T) {
	sink := &fakeSink{supported: true}
	controller := New(sink)

	successes := 0
	if !controller.PushAsync(func() Batch { return Batch{TrafficCount: 3, OnlineCount: 2} }, nil, func(batch Batch) {
		successes++
		if batch.TrafficCount != 3 || batch.OnlineCount != 2 {
			t.Fatalf("unexpected success batch: %#v", batch)
		}
	}) {
		t.Fatal("report should start")
	}

	deadline := time.Now().Add(time.Second)
	for controller.Active() && time.Now().Before(deadline) {
		time.Sleep(time.Millisecond)
	}
	if successes != 1 {
		t.Fatalf("success callbacks = %d, want 1", successes)
	}
}

func TestPushSyncBypassesAsyncBackoffAndReports(t *testing.T) {
	sink := &fakeSink{supported: true}
	controller := New(sink)
	controller.backoff.onFailure()

	if err := controller.PushSync(func() Batch {
		return Batch{Payload: controlplane.ReportPayload{CPU: 12.5}}
	}); err != nil {
		t.Fatalf("PushSync: %v", err)
	}
	if sink.callCount() != 1 {
		t.Fatalf("sink calls = %d, want 1", sink.callCount())
	}
}

func TestUnsupportedSinkDoesNotPrepare(t *testing.T) {
	sink := &fakeSink{supported: false}
	controller := New(sink)
	prepares := 0

	if controller.PushAsync(func() Batch {
		prepares++
		return Batch{}
	}, nil, nil) {
		t.Fatal("unsupported sink must not start report")
	}
	if prepares != 0 || sink.callCount() != 0 {
		t.Fatalf("unexpected work prepares=%d calls=%d", prepares, sink.callCount())
	}
}

func TestAmbiguousFailureRetriesSameBatchIDWithoutFlushingNewTraffic(t *testing.T) {
	sink := &fakeSink{supported: true, err: errors.New("lost acknowledgement")}
	controller := New(sink)
	prepares := 0
	prepare := func() Batch {
		prepares++
		return Batch{Payload: controlplane.ReportPayload{
			Traffic: map[int][2]int64{7: {123, 456}},
		}, TrafficCount: 1}
	}
	fail := make(chan struct{}, 1)
	if !controller.PushAsync(prepare, func(batch Batch, err error) {
		if len(batch.Payload.Traffic) > 0 {
			t.Error("ambiguous batch must not be restored into counter accumulator")
		}
		fail <- struct{}{}
	}, nil) {
		t.Fatal("initial attempt rejected")
	}
	<-fail
	for controller.Active() {
		time.Sleep(time.Millisecond)
	}
	// Backoff excludes one attempt without touching tracker data.
	if controller.PushAsync(prepare, nil, nil) {
		t.Fatal("backoff not applied")
	}
	sink.mu.Lock()
	sink.err = nil
	sink.mu.Unlock()
	success := make(chan struct{}, 1)
	if !controller.PushAsync(prepare, nil, func(Batch) { success <- struct{}{} }) {
		t.Fatal("retry rejected")
	}
	<-success
	sink.mu.Lock()
	ids := append([]string(nil), sink.batchIDs...)
	sink.mu.Unlock()
	if len(ids) != 2 || ids[0] == "" || ids[0] != ids[1] {
		t.Fatalf("replay IDs are not stable: %#v", ids)
	}
	if prepares != 1 {
		t.Fatalf("retry should not flush a new tracker batch; prepares = %d", prepares)
	}
}
