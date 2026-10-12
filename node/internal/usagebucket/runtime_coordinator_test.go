//go:build linux

package usagebucket

import (
	"context"
	"errors"
	"path/filepath"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

func waitRuntime(t *testing.T, condition func() bool) {
	t.Helper()
	deadline := time.Now().Add(3 * time.Second)
	for !condition() {
		if time.Now().After(deadline) {
			t.Fatal("runtime condition did not complete")
		}
		time.Sleep(time.Millisecond)
	}
}

func runtimeClockMeter(t *testing.T, elapsed *atomic.Int64) *Meter {
	t.Helper()
	m, err := New(Options{Enabled: true, Window: time.Hour, MaxEvents: 128, MaxSealed: 8, Clock: func() Stamp {
		at := elapsed.Load()
		return Stamp{WallUnixNanos: 1_800_000_000_000_000_000 + at, ElapsedNanos: at}
	}})
	if err != nil {
		t.Fatal(err)
	}
	return m
}

func closeRuntimeTest(t *testing.T, c *Coordinator) {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), time.Second)
	defer cancel()
	if err := c.Close(ctx); err != nil {
		t.Fatal(err)
	}
}

func TestAsyncOpeningDoesNotBlockCountersAndTimeoutKeepsOpeningFDLock(t *testing.T) {
	dir := filepath.Join(t.TempDir(), "journal")
	entered, release := make(chan struct{}), make(chan struct{})
	var releaseOnce sync.Once
	t.Cleanup(func() { releaseOnce.Do(func() { close(release) }) })
	var once sync.Once
	c, err := newAsyncCoordinator(CoordinatorOptions{}, func() (*Store, error) {
		return openStore(dir, StoreOptions{}, func(stage string) error {
			if stage == "before_file_sync" {
				once.Do(func() { close(entered); <-release })
			}
			return nil
		})
	})
	if err != nil {
		t.Fatal(err)
	}
	<-entered
	if !c.Status().Opening {
		t.Fatal("constructor waited for filesystem initialization")
	}
	var elapsed atomic.Int64
	m := runtimeClockMeter(t, &elapsed)
	if err := c.Add(m); err != nil {
		t.Fatal(err)
	}
	session := m.Bind(7, "sb-opening", "tcp")
	session.Callback(TCPReadCallback, Upload, Upload, 4)
	c.Retire(m)
	elapsed.Store(8_000_000_000)
	if operation := session.Begin(); operation.valid || !m.Status().Stopped || m.Status().Degraded {
		t.Fatal("stalled writer prevented callback-side retirement")
	}
	if got := m.Snapshot().Sealed[0].SealedAt.ElapsedNanos; got != 8_000_000_000 {
		t.Fatalf("retirement fabricated exact expiry: %d", got)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Millisecond)
	defer cancel()
	if err := c.Close(ctx); !errors.Is(err, context.DeadlineExceeded) {
		t.Fatalf("bounded open shutdown: %v", err)
	}
	if c.Status().Closed {
		t.Fatal("stalled opening worker declared completion")
	}
	if _, err := OpenStore(dir, StoreOptions{}); err == nil {
		t.Fatal("timed-out opening released worker's live flock")
	}
	session.Callback(TCPReadCallback, Upload, Upload, 11)
	if m.Status().DroppedEvents != 1 || m.Status().PersistedBuckets != 0 || m.Status().SealedBuckets != 1 {
		t.Fatal("unconfirmed evidence or unknown late tail lost")
	}
	releaseOnce.Do(func() { close(release) })
	waitRuntime(t, func() bool { return c.Status().Closed })
	if m.Status().PersistedBuckets != 0 {
		t.Fatal("worker dispatched fresh claim after shutdown deadline")
	}
	reopened, err := OpenStore(dir, StoreOptions{})
	if err != nil {
		t.Fatal(err)
	}
	defer reopened.Close()
	last, _ := reopened.Read(reopened.Status().Sequence)
	if !hasStoreReason(last, "UNSYNCED_TAIL_UNKNOWN") || !last.RestoreUnknown {
		t.Fatal("restart inferred lost RAM was complete")
	}
}

func TestRetirementUsesFiveSecondMonotonicGraceAndDurableDrainReleasesRegistry(t *testing.T) {
	c, err := NewAsyncCoordinator(filepath.Join(t.TempDir(), "journal"), StoreOptions{}, CoordinatorOptions{})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { closeRuntimeTest(t, c) })
	waitRuntime(t, func() bool { return !c.Status().Opening })
	var elapsed atomic.Int64
	m := runtimeClockMeter(t, &elapsed)
	if err := c.Add(m); err != nil {
		t.Fatal(err)
	}
	s := m.Bind(7, "sb-retired", "udp")
	s.Callback(UDPWriteCallback, Download, Upload, 4)
	s.Close()
	c.Retire(m)
	elapsed.Store(4_999_999_999)
	s.Callback(UDPWriteCallback, Download, Upload, 3)
	if m.Status().Stopped || !m.Status().Retiring {
		t.Fatal("grace ended before five monotonic seconds")
	}
	elapsed.Store(7_000_000_000)
	s.Callback(UDPWriteCallback, Download, Upload, 19)
	waitRuntime(t, func() bool { return c.Status().Meters == 0 })
	status := m.Status()
	if !status.Stopped || status.Degraded || status.PersistedBuckets != 1 || status.DroppedEvents != 1 || status.SealedBuckets != 0 || status.ClaimedBucket != 0 {
		t.Fatalf("normal retirement did not confirm before release: %+v", status)
	}
	store, _ := c.StoreSnapshot()
	record, err := c.ReadRecord(store.Sequence)
	if err != nil || record.Bucket == nil || record.Bucket.SealedAt.ElapsedNanos != 7_000_000_000 || record.Bucket.PhysicalTotals != [2]int64{0, 7} || record.Bucket.LegacyTotals != [2]int64{7, 0} || !record.Bucket.Events[2].LateAfterClose || !hasStoreReason(record, "RETIRED_TAIL_UNKNOWN") {
		t.Fatalf("retained original interval/direction: %+v %v", record, err)
	}
	if c.Status().RetainedEventCapacity != 0 || record.Authorizes || !record.TailUnknown || record.Capture == nil || !record.Capture.Stopped || record.Capture.StopReason != "RETIRED_TAIL_UNKNOWN" {
		t.Fatal("release implied complete billing coverage")
	}
}

func TestRuntimeStalledBucketSyncCannotReleaseOwnerOrDispatchAfterDeadline(t *testing.T) {
	dir := filepath.Join(t.TempDir(), "journal")
	entered, release := make(chan struct{}), make(chan struct{})
	var armed atomic.Bool
	var gateOnce, releaseOnce sync.Once
	t.Cleanup(func() { releaseOnce.Do(func() { close(release) }) })
	opened := make(chan *Store, 1)
	c, err := newAsyncCoordinator(CoordinatorOptions{}, func() (*Store, error) {
		s, err := openStore(dir, StoreOptions{}, func(stage string) error {
			if armed.Load() && stage == "before_file_sync" {
				gateOnce.Do(func() { close(entered); <-release })
			}
			return nil
		})
		if err == nil {
			opened <- s
		}
		return s, err
	})
	if err != nil {
		t.Fatal(err)
	}
	s := <-opened
	waitRuntime(t, func() bool { return !c.Status().Opening })
	armed.Store(true)
	var elapsed atomic.Int64
	m := runtimeClockMeter(t, &elapsed)
	session := m.Bind(7, "sb-sync", "tcp")
	session.Callback(TCPReadCallback, Upload, Upload, 17)
	if !m.Cut(ManualFence, false) || c.Add(m) != nil {
		t.Fatal("register retained bucket")
	}
	<-entered
	c.Retire(m)
	elapsed.Store(6_000_000_000)
	session.Callback(TCPReadCallback, Upload, Upload, 19)
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Millisecond)
	defer cancel()
	if err := c.Close(ctx); !errors.Is(err, context.DeadlineExceeded) || c.Status().Closed || !m.Status().Stopped || m.Status().PersistedBuckets != 0 || m.Status().ClaimedBucket != 1 {
		t.Fatal("runtime close released an unsynchronized claim")
	}
	if _, err := OpenStore(dir, StoreOptions{}); err == nil {
		t.Fatal("stalled bucket worker released exclusive lock")
	}
	if err := s.Close(); !errors.Is(err, ErrStoreOwner) {
		t.Fatal("external close interrupted worker-owned file descriptor")
	}
	if _, err := NewCoordinator(s, CoordinatorOptions{}); !errors.Is(err, ErrStoreOwner) {
		t.Fatal("replacement worker took over existing sync")
	}
	releaseOnce.Do(func() { close(release) })
	waitRuntime(t, func() bool { return c.Status().Closed })
	if m.Status().PersistedBuckets != 1 || m.Status().SealedBuckets != 0 || m.Status().DroppedEvents != 1 {
		t.Fatal("completed original claim or unknown late tail changed")
	}
	store, _ := c.StoreSnapshot()
	if store.Buckets != 1 {
		t.Fatal("worker dispatched new buckets after deadline")
	}
	record, err := c.ReadRecord(2)
	if err != nil || record.Bucket == nil || record.Bucket.PhysicalTotals != [2]int64{17, 0} || !record.TailUnknown {
		t.Fatal("original sealed evidence was rewritten")
	}
}

func TestRuntimeShutdownEndsGraceEarlyAndDrainsExistingEvidence(t *testing.T) {
	c, err := NewAsyncCoordinator(filepath.Join(t.TempDir(), "journal"), StoreOptions{}, CoordinatorOptions{})
	if err != nil {
		t.Fatal(err)
	}
	var elapsed atomic.Int64
	m := runtimeClockMeter(t, &elapsed)
	_ = c.Add(m)
	m.Bind(7, "sb-shutdown", "tcp").Callback(TCPReadCallback, Upload, Upload, 13)
	c.Retire(m)
	closeRuntimeTest(t, c)
	if m.Status().StopReason != "SHUTDOWN_TAIL_UNKNOWN" || m.Status().PersistedBuckets != 1 || c.Status().Meters != 0 {
		t.Fatalf("shutdown treated five-second grace as join requirement: %+v %+v", m.Status(), c.Status())
	}
	store, _ := c.StoreSnapshot()
	end, _ := c.ReadRecord(store.Sequence)
	bucket, _ := c.ReadRecord(store.Sequence - 1)
	if end.Kind != "PROCESS_END" || !end.TailUnknown || !hasStoreReason(bucket, "SHUTDOWN_TAIL_UNKNOWN") {
		t.Fatal("normal drain failed to retain unknown tail")
	}
}

func TestAsyncFailureAndLifetimeOwnerNeverPermitReplacement(t *testing.T) {
	owner := &ProcessOwner{}
	if !owner.Claim() {
		t.Fatal("first assignment refused")
	}
	c, err := NewAsyncCoordinator(filepath.Join(t.TempDir(), "invalid"), StoreOptions{MaxBytes: 1}, CoordinatorOptions{})
	if err != nil {
		t.Fatal(err)
	}
	waitRuntime(t, func() bool { return c.Status().Closed })
	if !c.Status().Degraded || owner.Claim() {
		t.Fatal("failed initialization released permanent process ownership")
	}
	var successes atomic.Int64
	var workers sync.WaitGroup
	for i := 0; i < 32; i++ {
		workers.Add(1)
		go func() {
			defer workers.Done()
			if owner.Claim() {
				successes.Add(1)
			}
		}()
	}
	workers.Wait()
	if successes.Load() != 0 {
		t.Fatal("concurrent replacement was allowed")
	}
}
