package usagebucket

import (
	"math"
	"reflect"
	"sync"
	"testing"
	"time"
)

func testMeter(t *testing.T, options Options) *Meter {
	t.Helper()
	options.Enabled = true
	m, err := New(options)
	if err != nil {
		t.Fatal(err)
	}
	return m
}
func snapshotEvents(snapshot Snapshot) []Event {
	var events []Event
	for _, b := range snapshot.Sealed {
		events = append(events, b.Events...)
	}
	return append(events, snapshot.Open.Events...)
}

func TestDisabledMeterDoesNoClockAllocationOrWork(t *testing.T) {
	options := Options{Clock: func() Stamp { panic("disabled clock read") }}
	if allocations := testing.AllocsPerRun(100, func() {
		m, err := New(options)
		if m != nil || err != nil {
			t.Fatal("disabled constructor")
		}
	}); allocations != 0 {
		t.Fatalf("disabled allocations: %v", allocations)
	}
	var session *Session
	if allocations := testing.AllocsPerRun(100, func() {
		op := session.Begin()
		session.Complete(op, TCPRead, Upload, Upload, 1, false, true)
		session.Callback(TCPReadCallback, Upload, Upload, 1)
		session.Close()
	}); allocations != 0 {
		t.Fatalf("disabled session allocations: %v", allocations)
	}
}

func TestCutKeepsHalfOpenSequencesAndImmutableSnapshots(t *testing.T) {
	at := Stamp{100, 10}
	m := testMeter(t, Options{Clock: func() Stamp { return at }})
	s := m.Bind(7, "sb-1", "tcp")
	op := s.Begin()
	at = Stamp{110, 20}
	s.Complete(op, TCPRead, Upload, Upload, 3, false, true)
	if !m.Cut(ManualFence, true) {
		t.Fatal("cut failed")
	}
	first := m.Snapshot()
	before := first.Sealed[0]
	at = Stamp{120, 30}
	s.Callback(TCPReadCallback, Upload, Upload, 5)
	first.Sealed[0].Events[0].RawCount = 999
	first.Sealed[0].PhysicalTotals[0] = 999
	latest := m.Snapshot()
	if latest.Sealed[0].Events[0].RawCount != 3 || latest.Sealed[0].PhysicalTotals[0] != 3 {
		t.Fatal("snapshot altered sealed record")
	}
	if before.StartSequence != 1 || before.EndSequenceExclusive != 2 || latest.Open.StartSequence != 2 || latest.Open.Events[0].Sequence != 2 || latest.Open.Events[0].BindingGeneration != 1 || latest.Open.Generation != 2 {
		t.Fatalf("local sequence/generation: %+v", latest)
	}
	if !latest.Open.Events[0].IntervalUnknown || latest.Open.Events[0].HasIOStart {
		t.Fatal("callback invented operation range")
	}
	for _, event := range snapshotEvents(latest) {
		if event.Attribution != "unattributable" {
			t.Fatal("attributed event")
		}
	}
}

func TestDirectOperationCrossingFenceKeepsMeasuredRange(t *testing.T) {
	at := Stamp{100, 10}
	m := testMeter(t, Options{Clock: func() Stamp { return at }})
	s := m.Bind(7, "sb-1", "tcp")
	op := s.Begin()
	at = Stamp{105, 15}
	if !m.Cut(LifecycleFence, true) {
		t.Fatal("fence")
	}
	at = Stamp{110, 20}
	s.Complete(op, TCPWrite, Download, Download, 4, true, true)
	e := m.Snapshot().Open.Events[0]
	if !e.HasIOStart || e.IOStarted != (Stamp{100, 10}) || e.Completed != (Stamp{110, 20}) || !e.CrossedFence || !e.IOError || e.BindingGeneration != 1 || e.ObservationGeneration != 2 {
		t.Fatalf("direct range: %+v", e)
	}
}

func TestClockRollbackDuringCompletionMarksFenceAtSameMonotonicResolution(t *testing.T) {
	at := Stamp{100, 10}
	m := testMeter(t, Options{Clock: func() Stamp { return at }})
	s := m.Bind(7, "sb-1", "tcp")
	op := s.Begin()
	at = Stamp{90, 10}
	s.Complete(op, TCPRead, Upload, Upload, 1, false, true)
	e := m.Snapshot().Open.Events[0]
	if !e.CrossedFence || e.ClockEra != 2 {
		t.Fatalf("completion clock fence not marked: %+v", e)
	}
}

func TestCloseAndLateCallbacksStayOriginalBindingAndNeverReopen(t *testing.T) {
	m := testMeter(t, Options{})
	s := m.Bind(7, "sb-original", "udp")
	op := s.Begin()
	s.Close()
	s.Close()
	if !m.Cut(LifecycleFence, true) {
		t.Fatal("fence")
	}
	sealed := m.Snapshot().Sealed[0]
	_ = m.Bind(8, "sb-replacement", "udp")
	s.Complete(op, UDPRead, Upload, Upload, 4, false, true)
	s.Callback(UDPWriteCallback, Download, Upload, 6)
	latest := m.Snapshot()
	if !reflect.DeepEqual(sealed, latest.Sealed[0]) {
		t.Fatal("late completion mutated sealed bucket")
	}
	if len(latest.Open.Events) != 2 {
		t.Fatal("late events lost")
	}
	for _, event := range latest.Open.Events {
		if !event.LateAfterClose || event.CloseFenceSequence != 1 || event.ConnectionID != "sb-original" || event.UserID != 7 || event.BindingGeneration != 1 || event.ObservationGeneration != 2 {
			t.Fatalf("late event rebound: %+v", event)
		}
	}
	if !latest.Open.Events[1].DirectionMismatch || latest.Open.PhysicalTotals != [2]int64{4, 6} || latest.Open.LegacyTotals != [2]int64{10, 0} {
		t.Fatalf("legacy direction lost: %+v", latest.Open)
	}
}

func TestWallRollbackUsesNewEraAndMonotonicRollbackDegrades(t *testing.T) {
	at := Stamp{100, 10}
	m := testMeter(t, Options{Clock: func() Stamp { return at }})
	s := m.Bind(7, "sb-1", "tcp")
	s.Callback(TCPReadCallback, Upload, Upload, 3)
	at = Stamp{90, 20}
	s.Callback(TCPReadCallback, Upload, Upload, 4)
	snapshot := m.Snapshot()
	if snapshot.Status.Degraded || len(snapshot.Sealed) != 1 || snapshot.Sealed[0].Reason != ClockBoundary || snapshot.Open.ClockEra != 2 || snapshot.Open.Events[0].ClockEra != 2 {
		t.Fatalf("wall rollback: %+v", snapshot)
	}
	at = Stamp{95, 15}
	s.Callback(TCPReadCallback, Upload, Upload, 8)
	snapshot = m.Snapshot()
	if snapshot.Status.Reason != "MONOTONIC_ROLLBACK" || snapshot.Status.FirstGap.RawCount != 8 || snapshot.Status.DroppedEvents != 1 || len(snapshot.Open.Events) != 1 {
		t.Fatalf("monotonic regression not preserved: %+v", snapshot.Status)
	}
}

func TestTimeAndCapacityBoundsPreservePendingEvents(t *testing.T) {
	at := Stamp{100, 0}
	m := testMeter(t, Options{Clock: func() Stamp { return at }, Window: time.Nanosecond * 10, MaxEvents: 2, MaxSealed: 1})
	s := m.Bind(7, "sb-1", "tcp")
	s.Callback(TCPReadCallback, Upload, Upload, 1)
	at = Stamp{110, 10}
	s.Callback(TCPReadCallback, Upload, Upload, 2)
	s.Callback(TCPReadCallback, Upload, Upload, 3)
	sealed := m.Snapshot().Sealed[0]
	s.Callback(TCPReadCallback, Upload, Upload, 4)
	snapshot := m.Snapshot()
	if snapshot.Status.Reason != "CAPACITY_REACHED" || snapshot.Status.FirstGap.RawCount != 4 || snapshot.Status.DroppedEvents != 1 || len(snapshot.Open.Events) != 2 || !reflect.DeepEqual(sealed, snapshot.Sealed[0]) {
		t.Fatalf("capacity silently lost/mutated: %+v", snapshot)
	}
	s.Callback(TCPReadCallback, Upload, Upload, 5)
	if m.Snapshot().Status.DroppedEvents != 2 {
		t.Fatal("degraded drop not visible")
	}
}

func TestInvalidAndOverflowCountsNeverWrapOrReset(t *testing.T) {
	for _, mode := range []string{"negative", "overflow", "sequence", "generation"} {
		t.Run(mode, func(t *testing.T) {
			m := testMeter(t, Options{})
			s := m.Bind(7, "sb-1", "tcp")
			switch mode {
			case "negative":
				s.Callback(TCPReadCallback, Upload, Upload, -4)
			case "overflow":
				s.Callback(TCPReadCallback, Upload, Upload, math.MaxInt64)
				s.Callback(TCPReadCallback, Upload, Upload, 1)
			case "sequence":
				m.nextSequence = math.MaxUint64
				s.Callback(TCPReadCallback, Upload, Upload, 1)
			case "generation":
				m.generation = math.MaxUint64
				if m.Cut(LifecycleFence, true) {
					t.Fatal("overflow cut accepted")
				}
			}
			snapshot := m.Snapshot()
			if !snapshot.Status.Degraded {
				t.Fatal("invalid count accepted")
			}
			if mode == "negative" && snapshot.Status.FirstGap.RawCount != -4 {
				t.Fatal("negative count evidence lost")
			}
			if mode == "overflow" && (snapshot.Open.PhysicalTotals[0] != math.MaxInt64 || snapshot.Status.FirstGap.RawCount != 1 || len(snapshot.Open.Events) != 1) {
				t.Fatal("overflow wrapped/reset")
			}
		})
	}
}

func TestConcurrentCutsAndEventsPartitionEachCompletionExactlyOnce(t *testing.T) {
	m := testMeter(t, Options{Window: time.Hour, MaxEvents: 1024, MaxSealed: 32})
	s := m.Bind(7, "sb-1", "tcp")
	var wg sync.WaitGroup
	for i := 0; i < 8; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for n := 0; n < 64; n++ {
				op := s.Begin()
				s.Complete(op, TCPRead, Upload, Upload, 1, false, true)
			}
		}()
	}
	wg.Add(1)
	go func() {
		defer wg.Done()
		for i := 0; i < 16; i++ {
			if !m.Cut(ManualFence, false) {
				t.Error("concurrent cut failed")
			}
		}
	}()
	wg.Wait()
	snapshot := m.Snapshot()
	events := snapshotEvents(snapshot)
	if len(events) != 512 || snapshot.Status.Degraded {
		t.Fatalf("events lost: %d / %+v", len(events), snapshot.Status)
	}
	for i, event := range events {
		if event.Sequence != uint64(i+1) {
			t.Fatalf("sequence duplicate/gap: %+v", event)
		}
	}
	var total int64
	lastEnd := uint64(1)
	for _, b := range append(snapshot.Sealed, snapshot.Open) {
		if b.StartSequence != lastEnd {
			t.Fatal("bucket range gap")
		}
		for _, e := range b.Events {
			if e.Sequence < b.StartSequence || (b.Sealed && e.Sequence >= b.EndSequenceExclusive) {
				t.Fatal("event crosses sequence bucket")
			}
		}
		total += b.PhysicalTotals[0]
		if b.Sealed {
			lastEnd = b.EndSequenceExclusive
		}
	}
	if total != 512 {
		t.Fatal("atomic totals changed")
	}
}

func TestIndependentTrackersDoNotReuseLocalIdentity(t *testing.T) {
	first := testMeter(t, Options{})
	second := testMeter(t, Options{})
	if first.Snapshot().Open.TrackerID == second.Snapshot().Open.TrackerID {
		t.Fatal("tracker identity reused")
	}
	if !first.Snapshot().Status.VolatileTailUnknown || !first.Snapshot().Open.VolatileTailUnknown {
		t.Fatal("volatile evidence claimed durable")
	}
}
