//go:build linux

package usagebucket

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"reflect"
	"sync"
	"testing"
	"time"
)

func storeMeter(t *testing.T) *Meter {
	t.Helper()
	m := testMeter(t, Options{Window: time.Hour, MaxEvents: 128, MaxSealed: 8})
	s := m.Bind(7, "sb-1", "udp")
	s.Callback(UDPWriteCallback, Download, Upload, 11)
	if !m.Cut(ManualFence, true) {
		t.Fatal("cut")
	}
	return m
}

func newStore(t *testing.T, fault func(string) error) (*Store, string) {
	t.Helper()
	dir := filepath.Join(t.TempDir(), "journal")
	s, err := openStore(dir, StoreOptions{}, fault)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		if s.owner == nil {
			_ = s.Close()
		}
	})
	return s, dir
}

func manualOwner(t *testing.T, s *Store, m *Meter) (*Coordinator, *bucketClaim) {
	t.Helper()
	c := &Coordinator{store: s}
	m.mu.Lock()
	m.consumer = c
	m.mu.Unlock()
	claim := m.claimOldest(c)
	if claim == nil {
		t.Fatal("claim")
	}
	return c, claim
}

func waitStore(t *testing.T, condition func() bool) {
	t.Helper()
	deadline := time.Now().Add(3 * time.Second)
	for !condition() && time.Now().Before(deadline) {
		time.Sleep(time.Millisecond)
	}
	if !condition() {
		t.Fatal("store wait expired")
	}
}

func TestStoreClaimsReleaseOnlyAfterDurableLocalReceipt(t *testing.T) {
	s, _ := newStore(t, nil)
	m := storeMeter(t)
	owner, claim := manualOwner(t, s, m)
	if m.claimOldest(owner) != claim {
		t.Fatal("claim identity changed")
	}
	if m.acknowledge(owner, durableReceipt{}) {
		t.Fatal("empty ACK released")
	}
	receipt, err := s.persistClaim(claim)
	if err != nil {
		t.Fatal(err)
	}
	if len(m.Snapshot().Sealed) != 1 {
		t.Fatal("store removed before private ACK")
	}
	repeated, err := s.persistClaim(claim)
	if err != nil || repeated.sequence != receipt.sequence || s.Status().Sequence != 2 {
		t.Fatal("lost ACK duplicated record")
	}
	wrong := receipt
	wrong.digest = "wrong"
	if m.acknowledge(owner, wrong) {
		t.Fatal("wrong digest released")
	}
	wrong = receipt
	wrong.committed = false
	if m.acknowledge(owner, wrong) {
		t.Fatal("uncommitted released")
	}
	other := &Coordinator{store: s}
	if m.acknowledge(other, receipt) {
		t.Fatal("foreign owner released")
	}
	if !m.acknowledge(owner, receipt) || m.acknowledge(owner, receipt) {
		t.Fatal("ACK was not exactly once")
	}
	if m.Status().PersistedBuckets != 1 || len(m.Snapshot().Sealed) != 0 {
		t.Fatal("durable RAM release")
	}
	record, err := s.Read(2)
	if err != nil {
		t.Fatal(err)
	}
	if record.Authorizes || record.Kind != "BUCKET" || record.Attribution != "unattributable" || !record.RestoreUnknown || !record.TailUnknown || record.LocalReceiptID == "" || record.Bucket == nil || len(record.Bucket.Events) != 1 {
		t.Fatalf("not local evidence: %+v", record)
	}
	event := record.Bucket.Events[0]
	if event.PhysicalDirection != Download || event.LegacyDirection != Upload || !event.DirectionMismatch || !event.IntervalUnknown || event.HasIOStart {
		t.Fatal("unknown I/O/mapping changed")
	}
	record.Bucket.Events[0].RawCount = 999
	again, err := s.Read(2)
	if err != nil || again.Bucket.Events[0].RawCount != 11 {
		t.Fatal("reader mutated evidence")
	}
}

func TestStoreClaimSurvivesConcurrentCutsAndLateCallbacks(t *testing.T) {
	s, _ := newStore(t, nil)
	m := storeMeter(t)
	owner, claim := manualOwner(t, s, m)
	before := cloneBucket(claim.bucket)
	session := m.Bind(7, "sb-late", "tcp")
	var workers sync.WaitGroup
	workers.Add(2)
	go func() {
		defer workers.Done()
		for i := 0; i < 32; i++ {
			session.Callback(TCPReadCallback, Upload, Upload, 1)
		}
	}()
	go func() {
		defer workers.Done()
		for i := 0; i < 3; i++ {
			m.Cut(LifecycleFence, true)
		}
	}()
	workers.Wait()
	if !reflect.DeepEqual(before, claim.bucket) {
		t.Fatal("claimed sealed payload mutated")
	}
	receipt, err := s.persistClaim(claim)
	if err != nil || !m.acknowledge(owner, receipt) {
		t.Fatalf("claim ACK %v", err)
	}
	if m.Snapshot().Open.Events == nil {
		t.Fatal("late evidence lost")
	}
}

func TestStoreFailuresNeverAcknowledgeAndPreserveEvidence(t *testing.T) {
	stages := []string{"before_write", "before_file_sync", "after_file_sync", "before_publish", "before_dir_sync", "after_dir_sync", "before_head_sync", "after_head_sync", "before_head_publish", "before_head_dir_sync", "after_head_dir_sync", "before_pending_remove", "before_cleanup_dir_sync"}
	for _, stage := range stages {
		t.Run(stage, func(t *testing.T) {
			armed := false
			s, dir := newStore(t, func(at string) error {
				if armed && at == stage {
					return errors.New("injected disk gate failure")
				}
				return nil
			})
			m := storeMeter(t)
			owner, claim := manualOwner(t, s, m)
			armed = true
			receipt, err := s.persistClaim(claim)
			if err == nil || receipt.committed || m.acknowledge(owner, receipt) || len(m.Snapshot().Sealed) != 1 || !s.Status().Degraded {
				t.Fatal("failed persistence released evidence")
			}
			if _, err := s.persistClaim(claim); err == nil {
				t.Fatal("failed store resumed")
			}
			armed = false
			_ = s.Close()
			reopened, err := OpenStore(dir, StoreOptions{})
			if stage == "before_write" || stage == "before_cleanup_dir_sync" {
				if err != nil {
					t.Fatalf("known durable prefix restart %v", err)
				}
				defer reopened.Close()
				last, _ := reopened.Read(reopened.Status().Sequence)
				if !hasStoreReason(last, "UNSYNCED_TAIL_UNKNOWN") {
					t.Fatal("restart hid gap")
				}
			} else if !errors.Is(err, ErrStoreRecovery) {
				t.Fatalf("uncertain prefix resumed %v", err)
			}
		})
	}
}

func hasStoreReason(r StoredRecord, reason string) bool {
	for _, value := range r.Reasons {
		if value == reason {
			return true
		}
	}
	return false
}

func TestStoreNoOverwriteConflictAndConsecutiveBucketIdentity(t *testing.T) {
	for _, mode := range []string{"published_collision", "same_identity_conflict", "nonconsecutive"} {
		t.Run(mode, func(t *testing.T) {
			s, dir := newStore(t, nil)
			m := storeMeter(t)
			owner, claim := manualOwner(t, s, m)
			if mode == "published_collision" {
				path := filepath.Join(dir, recordName(2))
				if err := os.WriteFile(path, []byte("original"), 0600); err != nil {
					t.Fatal(err)
				}
				if _, err := s.persistClaim(claim); err == nil {
					t.Fatal("overwrote existing record")
				}
				data, _ := os.ReadFile(path)
				if string(data) != "original" {
					t.Fatal("original replaced")
				}
				return
			}
			receipt, err := s.persistClaim(claim)
			if err != nil {
				t.Fatal(err)
			}
			if mode == "same_identity_conflict" {
				changed := *claim
				changed.bucket = cloneBucket(claim.bucket)
				changed.bucket.Events[0].RawCount++
				changed.bucket.PhysicalTotals[1]++
				changed.bucket.LegacyTotals[0]++
				m.mu.Lock()
				m.claim = &changed
				m.mu.Unlock()
				if _, err := s.persistClaim(&changed); !errors.Is(err, ErrStoreEvidence) {
					t.Fatal("identity conflict accepted")
				}
				return
			}
			if !m.acknowledge(owner, receipt) {
				t.Fatal("ack")
			}
			m.Bind(7, "sb-2", "tcp").Callback(TCPReadCallback, Upload, Upload, 1)
			m.Cut(ManualFence, false)
			next := m.claimOldest(owner)
			next.bucket.Number++
			if _, err := s.persistClaim(next); !errors.Is(err, ErrStoreEvidence) {
				t.Fatal("sequence skipped")
			}
		})
	}
}

func TestStoreRestoreRejectsTamperMissingHeadAndPending(t *testing.T) {
	for _, mode := range []string{"tamper", "missing_record", "missing_head", "journal_ahead", "pending_head", "symlink"} {
		t.Run(mode, func(t *testing.T) {
			s, dir := newStore(t, nil)
			m := storeMeter(t)
			_, claim := manualOwner(t, s, m)
			if _, err := s.persistClaim(claim); err != nil {
				t.Fatal(err)
			}
			if err := s.Close(); err != nil {
				t.Fatal(err)
			}
			path := filepath.Join(dir, recordName(2))
			switch mode {
			case "tamper":
				data, _ := os.ReadFile(path)
				data[15] = 'x'
				_ = os.WriteFile(path, data, 0600)
			case "missing_record":
				_ = os.Remove(path)
			case "missing_head":
				_ = os.Remove(filepath.Join(dir+".state", "head.json"))
			case "journal_ahead":
				_ = os.Remove(filepath.Join(dir, recordName(3)))
			case "pending_head":
				_ = os.WriteFile(filepath.Join(dir+".state", ".pending-head-unknown"), []byte("retained"), 0600)
			case "symlink":
				target := filepath.Join(t.TempDir(), "outside")
				data, _ := os.ReadFile(path)
				_ = os.WriteFile(target, data, 0600)
				_ = os.Remove(path)
				_ = os.Symlink(target, path)
			}
			if opened, err := OpenStore(dir, StoreOptions{}); err == nil {
				_ = opened.Close()
				t.Fatal("restored invalid evidence")
			}
		})
	}
}

func TestStoreIndependentHeadDetectsJournalOnlyRollbackAndFullRestoreRemainsUnknown(t *testing.T) {
	s, dir := newStore(t, nil)
	headBefore, err := os.ReadFile(filepath.Join(dir+".state", "head.json"))
	if err != nil {
		t.Fatal(err)
	}
	m := storeMeter(t)
	_, claim := manualOwner(t, s, m)
	if _, err := s.persistClaim(claim); err != nil {
		t.Fatal(err)
	}
	if err := s.Close(); err != nil {
		t.Fatal(err)
	}
	for _, seq := range []uint64{2, 3} {
		if err := os.Remove(filepath.Join(dir, recordName(seq))); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := OpenStore(dir, StoreOptions{}); !errors.Is(err, ErrStoreRecovery) {
		t.Fatalf("head failed to detect rollback %v", err)
	}
	if err := os.WriteFile(filepath.Join(dir+".state", "head.json"), headBefore, 0600); err != nil {
		t.Fatal(err)
	}
	restored, err := OpenStore(dir, StoreOptions{})
	if err != nil {
		t.Fatal(err)
	}
	defer restored.Close()
	last, _ := restored.Read(restored.Status().Sequence)
	if !last.RestoreUnknown || !hasStoreReason(last, "RESTORE_NOT_EXTERNALLY_VERIFIED") || !hasStoreReason(last, "UNSYNCED_TAIL_UNKNOWN") {
		t.Fatal("whole-history restore asserted completeness")
	}
}

func TestStoreLockCapacityAndCoordinatorRegistryAreBounded(t *testing.T) {
	s, dir := newStore(t, nil)
	if _, err := OpenStore(dir, StoreOptions{}); err == nil {
		t.Fatal("second writer acquired lock")
	}
	m := storeMeter(t)
	_, claim := manualOwner(t, s, m)
	s.maxBytes = s.usedBytes + storeControlReserve + 8192
	if _, err := s.persistClaim(claim); !errors.Is(err, ErrStoreCapacity) || len(m.Snapshot().Sealed) != 1 {
		t.Fatal("capacity released evidence")
	}
	s2, _ := newStore(t, nil)
	c, err := NewCoordinator(s2, CoordinatorOptions{MaxMeters: 1})
	if err != nil {
		t.Fatal(err)
	}
	one := testMeter(t, Options{})
	two := testMeter(t, Options{})
	if err := c.Add(one); err != nil {
		t.Fatal(err)
	}
	if err := c.Add(two); !errors.Is(err, ErrCoordinatorCapacity) || !one.Status().Degraded || !two.Status().Degraded {
		t.Fatal("registry overflow not fail-stop")
	}
	ctx, cancel := context.WithTimeout(context.Background(), time.Second)
	defer cancel()
	_ = c.Close(ctx)
}

func TestCoordinatorStalledSyncDoesNotHoldMeterAndTimedCloseKeepsExclusiveOwner(t *testing.T) {
	entered, release := make(chan struct{}), make(chan struct{})
	armed := false
	var gateOnce sync.Once
	s, dir := newStore(t, func(stage string) error {
		if armed && stage == "before_file_sync" {
			gateOnce.Do(func() { close(entered); <-release })
		}
		return nil
	})
	armed = true
	m := storeMeter(t)
	c, err := NewCoordinator(s, CoordinatorOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if err := c.Add(m); err != nil {
		t.Fatal(err)
	}
	<-entered
	completed := make(chan struct{})
	go func() {
		m.Bind(7, "sb-concurrent", "tcp").Callback(TCPReadCallback, Upload, Upload, 3)
		m.Cut(LifecycleFence, true)
		_ = m.Status()
		_ = c.Status()
		_ = s.Status()
		close(completed)
	}()
	select {
	case <-completed:
	case <-time.After(time.Second):
		t.Fatal("disk wait held meter/status lock")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Millisecond)
	defer cancel()
	if err := c.Close(ctx); !errors.Is(err, context.DeadlineExceeded) {
		t.Fatal("stalled close not bounded")
	}
	if _, err := OpenStore(dir, StoreOptions{}); err == nil {
		t.Fatal("timed close released live lock")
	}
	if err := s.Close(); !errors.Is(err, ErrStoreOwner) {
		t.Fatal("external caller closed worker store")
	}
	if _, err := NewCoordinator(s, CoordinatorOptions{}); !errors.Is(err, ErrStoreOwner) {
		t.Fatal("second worker joined a stalled store")
	}
	close(release)
	ctx2, cancel2 := context.WithTimeout(context.Background(), time.Second)
	defer cancel2()
	if err := c.Close(ctx2); err != nil {
		t.Fatal(err)
	}
	if m.Status().PersistedBuckets != 1 || m.Status().SealedBuckets != 1 {
		t.Fatal("shutdown rewrote undispatched queue")
	}
}

func TestCoordinatorBeforeAckLossKeepsOriginalDurableBucket(t *testing.T) {
	armed := false
	s, _ := newStore(t, func(stage string) error {
		if armed && stage == "before_ack" {
			return errors.New("ACK lost")
		}
		return nil
	})
	armed = true
	m := storeMeter(t)
	c, err := NewCoordinator(s, CoordinatorOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if err := c.Add(m); err != nil {
		t.Fatal(err)
	}
	waitStore(t, func() bool { return c.Status().Closed })
	if m.Status().PersistedBuckets != 0 || m.Status().SealedBuckets != 1 || !m.Status().Degraded {
		t.Fatal("lost ACK released RAM")
	}
	if s.Status().Buckets != 1 {
		t.Fatal("lost ACK forgot durable bucket")
	}
	record, err := s.Read(2)
	if err != nil || record.Bucket == nil || record.Bucket.Events[0].RawCount != 11 {
		t.Fatal("lost ACK replaced evidence")
	}
}

func TestCoordinatorTotalRetainedEventCapacityIsBounded(t *testing.T) {
	s, _ := newStore(t, nil)
	c, err := NewCoordinator(s, CoordinatorOptions{})
	if err != nil {
		t.Fatal(err)
	}
	one := testMeter(t, Options{}) // default capacity 1024 * (32+1)
	two := testMeter(t, Options{})
	if err := c.Add(one); err != nil {
		t.Fatal(err)
	}
	if err := c.Add(two); !errors.Is(err, ErrCoordinatorCapacity) {
		t.Fatal("aggregate retained event limit exceeded")
	}
	if c.Status().RetainedEventCapacity > 65536 || !one.Status().Degraded || !two.Status().Degraded {
		t.Fatal("capacity failure hidden")
	}
	ctx, cancel := context.WithTimeout(context.Background(), time.Second)
	defer cancel()
	_ = c.Close(ctx)
}

func TestStoreReadsRefuseRehashedTamperAgainstCommittedHead(t *testing.T) {
	s, dir := newStore(t, nil)
	m := storeMeter(t)
	_, claim := manualOwner(t, s, m)
	if _, err := s.persistClaim(claim); err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(dir, recordName(2))
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var envelope journalEnvelope
	if err := decodeCanonical(data, &envelope); err != nil {
		t.Fatal(err)
	}
	envelope.Record.Bucket.Events[0].RawCount++
	envelope.Record.Bucket.PhysicalTotals[1] = "12"
	envelope.Record.Bucket.LegacyTotals[0] = "12"
	bucketBytes, _ := json.Marshal(envelope.Record.Bucket)
	envelope.Record.Receipt.BucketDigest = digestBytes(bucketBytes)
	payload, _ := json.Marshal(envelope.Record)
	envelope.Digest = digestBytes(payload)
	data, _ = json.Marshal(envelope)
	if err := os.WriteFile(path, data, 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := s.Read(2); !errors.Is(err, ErrStoreEvidence) {
		t.Fatal("rehash replaced a committed record")
	}
}

func TestStoreRestartReturnsSameLocalReceiptForOriginalSealedIdentity(t *testing.T) {
	s, dir := newStore(t, nil)
	m := storeMeter(t)
	owner, claim := manualOwner(t, s, m)
	original, err := s.persistClaim(claim)
	if err != nil {
		t.Fatal(err)
	}
	if err := s.Close(); err != nil {
		t.Fatal(err)
	}
	restarted, err := OpenStore(dir, StoreOptions{})
	if err != nil {
		t.Fatal(err)
	}
	defer restarted.Close()
	owner.store = restarted
	before := restarted.Status().Sequence
	replayed, err := restarted.persistClaim(claim)
	if err != nil || replayed.sequence != original.sequence || replayed.digest != original.digest || restarted.Status().Sequence != before {
		t.Fatal("restart duplicated sealed evidence or receipt")
	}
	if !m.acknowledge(owner, replayed) {
		t.Fatal("original claim not releasable after durable retry")
	}
}
