package usagebucket

import "math"

// RuntimeRetirementGrace begins after actual box Close and cancellation. It is
// observation retention, never proof that callbacks or I/O have all finished.
const RuntimeRetirementGraceNanos int64 = 5_000_000_000

func (m *Meter) retire() {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.retiring || m.stopped != "" || m.degraded != "" {
		return
	}
	at, ok := m.stamp()
	if !ok {
		return
	}
	if at.ElapsedNanos > math.MaxInt64-RuntimeRetirementGraceNanos {
		m.fail("RETIREMENT_CLOCK_OVERFLOW", Event{Completed: at, Attribution: "unattributable"})
		return
	}
	m.retiring = true
	m.retireAt = at.ElapsedNanos + RuntimeRetirementGraceNanos
}

// Caller holds m.mu. The actual check stamp is used, not a fabricated deadline.
func (m *Meter) checkRetirement(at Stamp) bool {
	if !m.retiring || at.ElapsedNanos < m.retireAt {
		return false
	}
	m.finishObservation(at, "RETIRED_TAIL_UNKNOWN")
	return true
}

func (m *Meter) finishObservation(at Stamp, reason string) {
	if m.stopped != "" || m.degraded != "" {
		return
	}
	// An empty open bucket needs no final payload. Sealed queues retain their
	// exact identities. Capacity failure preserves the open tail and fail-stops.
	if len(m.open.Events) != 0 && !m.seal(at, LifecycleFence) {
		return
	}
	m.stopped = reason
}

func (m *Meter) finishForShutdown() {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.stopped != "" || m.degraded != "" {
		return
	}
	at, ok := m.stamp()
	if ok {
		m.finishObservation(at, "SHUTDOWN_TAIL_UNKNOWN")
	}
}

func (m *Meter) tick() {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.stopped != "" || m.degraded != "" {
		return
	}
	at, ok := m.stamp()
	if !ok || m.checkRetirement(at) {
		return
	}
	if len(m.open.Events) != 0 && at.ElapsedNanos-m.open.Started.ElapsedNanos >= m.window {
		m.seal(at, TimeBoundary)
	}
}

func (m *Meter) drained() bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.stopped != "" && m.degraded == "" && len(m.open.Events) == 0 && len(m.sealed) == 0 && m.claim == nil
}
