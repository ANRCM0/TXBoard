package usagebucket

// Claims and receipts are private to this package. Only the persistence
// coordinator can release retained evidence; caller-supplied ACKs are not an API.
type bucketClaim struct {
	meter  *Meter
	owner  *Coordinator
	bucket Bucket
	status Status
	digest string
}

type durableReceipt struct {
	store     *Store
	claim     *bucketClaim
	key       string
	digest    string
	sequence  uint64
	committed bool
}

func (m *Meter) claimOldest(owner *Coordinator) *bucketClaim {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.consumer != owner || len(m.sealed) == 0 {
		return nil
	}
	if m.claim == nil {
		// This shallow copy is private, immutable and remains backed by retained
		// sealed storage. Public snapshots still return independent deep copies.
		m.claim = &bucketClaim{meter: m, owner: owner, bucket: m.sealed[0], status: m.statusLocked()}
	}
	return m.claim
}

func (m *Meter) acknowledge(owner *Coordinator, receipt durableReceipt) bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	claim := m.claim
	if claim == nil || m.consumer != owner || claim.owner != owner || receipt.store != owner.store || !receipt.committed || receipt.claim != claim || receipt.key != bucketKey(claim.bucket) || receipt.digest == "" || receipt.digest != claim.digest || receipt.sequence == 0 || len(m.sealed) == 0 || m.sealed[0].Number != claim.bucket.Number || m.sealed[0].TrackerID != claim.bucket.TrackerID {
		return false
	}
	// The store computed this digest from the private claim, not a mutable
	// public snapshot. Receipt construction follows every durability gate.
	m.sealed[0] = Bucket{}
	m.sealed = m.sealed[1:]
	m.claim = nil
	m.persisted++
	return true
}

func (m *Meter) stopPersistence(reason string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.fail(reason, Event{Attribution: "unattributable"})
}
