package usagev2

import (
	"context"
	"encoding/json"
	"errors"
	"sync"
)

type Transport interface {
	Submit(context.Context, Submission) (Receipt, error)
	Get(context.Context, Query) (Receipt, bool, error)
}

type Reporter struct {
	session   Session
	spool     *Spool
	transport Transport
	mu        sync.Mutex
	// A failed write retains the same ID in memory until its durability can be
	// retried; accepting fresh counter intervals in that state would risk loss.
	unconfirmed *Record
}

func NewReporter(session Session, spool *Spool, transport Transport) (*Reporter, error) {
	if err := session.Validate(); err != nil {
		return nil, err
	}
	if spool == nil || transport == nil {
		return nil, errors.New("usage v2 reporter requires durable spool and transport")
	}
	// Do not share mutable grant slices with caller-owned handshake objects.
	b, _ := json.Marshal(session)
	var copySession Session
	if err := json.Unmarshal(b, &copySession); err != nil {
		return nil, err
	}
	records, err := spool.Records()
	if err != nil {
		return nil, err
	}
	for _, record := range records {
		if record.Session.CoreInstanceID != session.CoreInstanceID || record.Submission.Payload.NodeID != session.NodeID {
			return nil, ErrReceiptMismatch
		}
		if record.Session.ReceiptGeneration != session.ReceiptGeneration {
			return nil, ErrRecoveryRequired
		}
		// Old source_epoch remains immutable and is explicitly allowed for
		// historical replay; no current grants are applied to that old batch.
	}
	if err := spool.BindSession(copySession); err != nil {
		return nil, err
	}
	return &Reporter{session: copySession, spool: spool, transport: transport}, nil
}

// Capture transfers ownership of already bounded counters to the reporter.
// The returned submission must stay with the collector if validation fails.
// Once a batch ID is returned, persistence failures belong to this reporter:
// retry with RetryPersistence, never roll those bytes into another batch.
func (r *Reporter) Capture(startedAt, endedAt int64, raw map[int][2]int64) (Submission, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.unconfirmed != nil {
		return Submission{}, errors.New("usage v2 prior batch durability unconfirmed; stop new collection")
	}
	records, err := r.spool.Records()
	if err != nil {
		return Submission{}, err
	}
	for _, record := range records {
		if startedAt < record.Submission.Payload.EndedAt {
			return Submission{}, errors.New("usage v2 collection interval overlaps a captured interval; refuse duplicate counters or clock rollback")
		}
	}
	submission, err := r.session.Build(startedAt, endedAt, raw)
	if err != nil {
		return Submission{}, err
	}
	record := Record{SchemaVersion: 1, Session: r.session, Submission: submission}
	if err := r.spool.Save(record); err != nil {
		r.unconfirmed = &record
		return submission, err
	}
	return submission, nil
}

func (r *Reporter) RetryPersistence() error {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.unconfirmed == nil {
		return nil
	}
	if err := r.spool.Save(*r.unconfirmed); err != nil {
		return err
	}
	r.unconfirmed = nil
	return nil
}

// Deliver queries before replay, persists receipts before advancing, and never
// deletes payloads. The caller provides bounded timeout/backoff contexts.
func (r *Reporter) Deliver(ctx context.Context) error {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.unconfirmed != nil {
		return errors.New("usage v2 batch durability unconfirmed; delivery blocked")
	}
	records, err := r.spool.Records()
	if err != nil {
		return err
	}
	for _, record := range records {
		if err := ctx.Err(); err != nil {
			return err
		}
		receipt, found, err := r.transport.Get(ctx, record.Submission.Query())
		if err != nil {
			return err
		}
		if !found {
			if record.Receipt != nil {
				return ErrRecoveryRequired
			}
			receipt, err = r.transport.Submit(ctx, record.Submission)
			if err != nil {
				return err
			}
		}
		if err := receipt.Validate(record.Session, record.Submission); err != nil {
			return err
		}
		record.Receipt = &receipt
		if err := r.spool.Save(record); err != nil {
			return err
		}
	}
	return nil
}

func (r *Reporter) Close() error { return r.spool.Close() }
