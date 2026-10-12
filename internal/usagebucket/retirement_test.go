package usagebucket

import (
	"testing"
	"time"
)

func retirementMeter(t *testing.T, at *Stamp, maxSealed int) *Meter {
	t.Helper()
	m, err := New(Options{Enabled: true, Window: time.Hour, MaxEvents: 8, MaxSealed: maxSealed, Clock: func() Stamp { return *at }})
	if err != nil {
		t.Fatal(err)
	}
	return m
}

func TestRetiredOperationCompletionKeepsUnconfirmedOriginAndUnknownLateTail(t *testing.T) {
	at := Stamp{WallUnixNanos: 1_000_000_000_000, ElapsedNanos: 0}
	m := retirementMeter(t, &at, 4)
	s := m.Bind(7, "sb-original", "tcp")
	s.Callback(TCPReadCallback, Upload, Upload, 3)
	m.retire()
	at.ElapsedNanos = 4_000_000_000
	at.WallUnixNanos += at.ElapsedNanos
	op := s.Begin()
	at.ElapsedNanos = 7_000_000_000
	at.WallUnixNanos += 3_000_000_000
	s.Complete(op, TCPRead, Upload, Upload, 9, false, true)
	before := m.Snapshot()
	if !before.Status.Stopped || before.Status.Degraded || before.Status.DroppedEvents != 1 || len(before.Sealed) != 1 || before.Sealed[0].PhysicalTotals != [2]int64{3, 0} || before.Sealed[0].SealedAt.ElapsedNanos != 7_000_000_000 {
		t.Fatalf("retirement interpolated a crossing operation: %+v", before)
	}
	s.Close()
	s.Callback(TCPReadCallback, Upload, Upload, 11)
	after := m.Snapshot()
	if after.Status.DroppedEvents != 3 || after.Sealed[0].EndSequenceExclusive != before.Sealed[0].EndSequenceExclusive || after.Sealed[0].PhysicalTotals != before.Sealed[0].PhysicalTotals || after.Status.PersistedBuckets != 0 || !after.Status.VolatileTailUnknown {
		t.Fatal("late callback reopened sealed history or claimed durability")
	}
}

func TestRetirementCapacityFailurePreservesOpenAndSealedEvidence(t *testing.T) {
	at := Stamp{WallUnixNanos: 1_000_000_000_000}
	m := retirementMeter(t, &at, 1)
	s := m.Bind(7, "sb-full", "udp")
	s.Callback(UDPWriteCallback, Download, Upload, 2)
	if !m.Cut(LifecycleFence, false) {
		t.Fatal("initial seal")
	}
	s.Callback(UDPWriteCallback, Download, Upload, 3)
	m.retire()
	at.ElapsedNanos = RuntimeRetirementGraceNanos
	at.WallUnixNanos += at.ElapsedNanos
	m.tick()
	snapshot := m.Snapshot()
	if !snapshot.Status.Degraded || snapshot.Status.Reason != "CAPACITY_REACHED" || snapshot.Status.Stopped || len(snapshot.Sealed) != 1 || snapshot.Sealed[0].PhysicalTotals != [2]int64{0, 2} || snapshot.Open.PhysicalTotals != [2]int64{0, 3} || m.drained() {
		t.Fatal("full queue dropped unconfirmed final evidence")
	}
}

func TestRetirementClockRollbackFailsObservationWithoutChangingEvidence(t *testing.T) {
	at := Stamp{WallUnixNanos: 1_000_000_000_000, ElapsedNanos: 100}
	m := retirementMeter(t, &at, 4)
	s := m.Bind(7, "sb-clock", "tcp")
	s.Callback(TCPReadCallback, Upload, Upload, 5)
	m.retire()
	at.ElapsedNanos = 99
	m.tick()
	snapshot := m.Snapshot()
	if !snapshot.Status.Degraded || snapshot.Status.Reason != "MONOTONIC_ROLLBACK" || snapshot.Status.Stopped || snapshot.Open.PhysicalTotals != [2]int64{5, 0} || m.drained() {
		t.Fatal("rollback was interpreted as expired complete tail")
	}
}
