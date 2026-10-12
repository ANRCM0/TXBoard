package usagev2

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"testing"
)

func digestOf(t *testing.T, p Payload) string {
	t.Helper()
	b, err := p.Canonical()
	if err != nil {
		t.Fatal(err)
	}
	hash := sha256.Sum256(b)
	return "sha256:" + hex.EncodeToString(hash[:])
}

func linuxSpool(t *testing.T) *Spool {
	t.Helper()
	if runtime.GOOS != "linux" {
		t.Skip("Linux file/directory fsync required")
	}
	s, err := OpenSpool(t.TempDir(), 0)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { s.Close() })
	return s
}

func TestClosedSpoolCannotReplaceNewOwnersSession(t *testing.T) {
	spool := linuxSpool(t)
	if err := spool.BindSession(session()); err != nil {
		t.Fatal(err)
	}
	if err := spool.Close(); err != nil {
		t.Fatal(err)
	}
	owner, err := OpenSpool(spool.dir, 0)
	if err != nil {
		t.Fatal(err)
	}
	defer owner.Close()
	next := session()
	next.SourceEpoch = "2"
	if err := owner.BindSession(next); err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(spool.dir, ".usage-session")
	before, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if err := spool.BindSession(session()); err == nil {
		t.Fatal("closed spool wrote metadata without owning the directory lock")
	}
	if _, err := NewReporter(session(), spool, &fakeTransport{}); err == nil {
		t.Fatal("reporter accepted a closed spool")
	}
	after, err := os.ReadFile(path)
	if err != nil || string(before) != string(after) {
		t.Fatalf("new owner's session was changed: %v", err)
	}
}

type fakeTransport struct {
	getReceipt     *Receipt
	getErr         error
	submitErr      error
	lostResponse   bool
	gets, submits  int
	lastSubmission Submission
}

func (f *fakeTransport) Get(_ context.Context, q Query) (Receipt, bool, error) {
	f.gets++
	if f.getErr != nil {
		return Receipt{}, false, f.getErr
	}
	if f.getReceipt == nil {
		return Receipt{}, false, nil
	}
	return *f.getReceipt, true, nil
}
func (f *fakeTransport) Submit(_ context.Context, s Submission) (Receipt, error) {
	f.submits++
	f.lastSubmission = s
	r := receiptFor(s, "pending")
	if f.lostResponse {
		f.getReceipt = &r
		return Receipt{}, errors.New("simulated commit then response loss")
	}
	return r, f.submitErr
}

func TestSpoolReplaysStableBatchAfterCrashAndPreservesReceiptPayload(t *testing.T) {
	spool := linuxSpool(t)
	dir := spool.dir
	transport := &fakeTransport{}
	reporter, err := NewReporter(session(), spool, transport)
	if err != nil {
		t.Fatal(err)
	}
	s, err := reporter.Capture(1791770400, 1791770460, map[int][2]int64{7: {1024, 2048}})
	if err != nil {
		t.Fatal(err)
	}
	if err := reporter.Close(); err != nil {
		t.Fatal(err)
	}
	reopened, err := OpenSpool(dir, 0)
	if err != nil {
		t.Fatal(err)
	}
	defer reopened.Close()
	reporter, err = NewReporter(session(), reopened, transport)
	if err != nil {
		t.Fatal(err)
	}
	if err := reporter.Deliver(context.Background()); err != nil {
		t.Fatal(err)
	}
	if transport.lastSubmission.Payload.BatchID != s.Payload.BatchID || transport.lastSubmission.PayloadDigest != s.PayloadDigest {
		t.Fatal("restart relabeled batch")
	}
	records, err := reopened.Records()
	if err != nil || len(records) != 1 || records[0].Receipt == nil {
		t.Fatalf("durable receipt missing: %v %v", records, err)
	}
	r := receiptFor(s, "settled")
	transport.getReceipt = &r
	if err := reporter.Deliver(context.Background()); err != nil {
		t.Fatal(err)
	}
	records, _ = reopened.Records()
	if len(records) != 1 || records[0].Receipt.SettlementStatus != "settled" {
		t.Fatal("settled payload was removed before retention/recovery proof")
	}
}

func TestLostReceiptRecoveredByQueryWithoutSecondSubmission(t *testing.T) {
	spool := linuxSpool(t)
	transport := &fakeTransport{lostResponse: true}
	r, err := NewReporter(session(), spool, transport)
	if err != nil {
		t.Fatal(err)
	}
	s, err := r.Capture(1791770400, 1791770460, map[int][2]int64{7: {11, 22}})
	if err != nil {
		t.Fatal(err)
	}
	if r.Deliver(context.Background()) == nil {
		t.Fatal("lost response unexpectedly confirmed")
	}
	if err := r.Deliver(context.Background()); err != nil {
		t.Fatal(err)
	}
	if transport.submits != 1 || transport.gets != 2 {
		t.Fatal("query-before-replay failed")
	}
	records, _ := spool.Records()
	if records[0].Submission.PayloadDigest != s.PayloadDigest || records[0].Receipt == nil {
		t.Fatal("query recovered altered batch")
	}
}

func TestOldEpochPendingIsNeverRelabeledByNewHandshake(t *testing.T) {
	spool := linuxSpool(t)
	transport := &fakeTransport{}
	r, err := NewReporter(session(), spool, transport)
	if err != nil {
		t.Fatal(err)
	}
	old, err := r.Capture(1791770400, 1791770460, map[int][2]int64{7: {1, 2}})
	if err != nil {
		t.Fatal(err)
	}
	nextSession := session()
	nextSession.SourceEpoch = "2"
	next, err := NewReporter(nextSession, spool, transport)
	if err != nil {
		t.Fatal(err)
	}
	if err := next.Deliver(context.Background()); err != nil {
		t.Fatal(err)
	}
	if transport.lastSubmission.Payload.SourceEpoch != "1" || transport.lastSubmission.Payload.BatchID != old.Payload.BatchID {
		t.Fatal("new epoch contaminated old pending")
	}
}

func TestDigestConflictAndQuarantineKeepOriginalRecord(t *testing.T) {
	spool := linuxSpool(t)
	original := Record{SchemaVersion: 1, Session: session(), Submission: golden(t)}
	if err := spool.Save(original); err != nil {
		t.Fatal(err)
	}
	conflict := original
	conflict.Submission.Payload.Entries = append([]Entry(nil), original.Submission.Payload.Entries...)
	conflict.Submission.Payload.Entries[0].RawUpBytes = "999"
	// Produce a valid altered digest while keeping the exact original identity.
	fresh, err := NewSubmission("42", "1", original.Submission.Payload.StartedAt, original.Submission.Payload.EndedAt, conflict.Submission.Payload.Entries)
	if err != nil {
		t.Fatal(err)
	}
	fresh.Payload.BatchID = original.Submission.Payload.BatchID
	conflict.Submission = fresh
	// Get correct digest for the changed batch_id via the canonical helper.
	conflict.Submission.PayloadDigest = digestOf(t, conflict.Submission.Payload)
	if err := spool.Save(conflict); !errors.Is(err, ErrPayloadConflict) {
		t.Fatalf("expected conflict: %v", err)
	}
	records, _ := spool.Records()
	if records[0].Submission.PayloadDigest != original.Submission.PayloadDigest {
		t.Fatal("conflict overwrote original")
	}
	q := receiptFor(original.Submission, "quarantined")
	original.Receipt = &q
	if err := spool.Save(original); err != nil {
		t.Fatal(err)
	}
	records, _ = spool.Records()
	if len(records) != 1 || records[0].Receipt.SettlementStatus != "quarantined" {
		t.Fatal("quarantine silently dropped")
	}
}

func TestReceiptMismatchAndGenerationRecoveryFailClosed(t *testing.T) {
	spool := linuxSpool(t)
	transport := &fakeTransport{}
	r, err := NewReporter(session(), spool, transport)
	if err != nil {
		t.Fatal(err)
	}
	s, err := r.Capture(1791770400, 1791770460, map[int][2]int64{7: {1, 2}})
	if err != nil {
		t.Fatal(err)
	}
	wrong := receiptFor(s, "pending")
	wrong.SourceEpoch = "2"
	transport.getReceipt = &wrong
	if err := r.Deliver(context.Background()); !errors.Is(err, ErrReceiptMismatch) {
		t.Fatalf("wrong epoch: %v", err)
	}
	records, _ := spool.Records()
	if records[0].Receipt != nil {
		t.Fatal("wrong receipt persisted")
	}
	valid := receiptFor(s, "pending")
	transport.getReceipt = &valid
	if err := r.Deliver(context.Background()); err != nil {
		t.Fatal(err)
	}
	transport.getReceipt = nil
	if err := r.Deliver(context.Background()); !errors.Is(err, ErrRecoveryRequired) {
		t.Fatalf("confirmed receipt disappeared: %v", err)
	}
	if transport.submits != 0 {
		t.Fatal("missing confirmed receipt treated as new batch")
	}
	next := session()
	next.ReceiptGeneration = "2"
	if _, err := NewReporter(next, spool, transport); !errors.Is(err, ErrRecoveryRequired) {
		t.Fatalf("generation change accepted: %v", err)
	}
}

func TestFileAndDirectoryFsyncFailureBlocksDeliveryAndNewCapture(t *testing.T) {
	for _, stage := range []string{"file", "directory"} {
		t.Run(stage, func(t *testing.T) {
			spool := linuxSpool(t)
			transport := &fakeTransport{}
			r, err := NewReporter(session(), spool, transport)
			if err != nil {
				t.Fatal(err)
			}
			fileSync, dirSync := spool.syncFile, spool.syncDir
			if stage == "file" {
				spool.syncFile = func(*os.File) error { return errors.New("disk fsync fault") }
			} else {
				spool.syncDir = func(string) error { return errors.New("directory fsync fault") }
			}
			s, err := r.Capture(1791770400, 1791770460, map[int][2]int64{7: {1, 2}})
			if err == nil || s.Payload.BatchID == "" {
				t.Fatal("failed fsync lost stable batch identity")
			}
			if r.Deliver(context.Background()) == nil || transport.gets != 0 || transport.submits != 0 {
				t.Fatal("unconfirmed disk write delivered")
			}
			if _, err := r.Capture(1791770460, 1791770470, map[int][2]int64{7: {3, 4}}); err == nil {
				t.Fatal("fresh traffic accepted under durability failure")
			}
			spool.syncFile, spool.syncDir = fileSync, dirSync
			if err := r.RetryPersistence(); err != nil {
				t.Fatal(err)
			}
			if err := r.Deliver(context.Background()); err != nil {
				t.Fatal(err)
			}
			if transport.lastSubmission.Payload.BatchID != s.Payload.BatchID {
				t.Fatal("durability retry assigned a new identity")
			}
		})
	}
}

func TestCorruptionOwnershipAndCapacityDoNotSilentlyLosePayload(t *testing.T) {
	spool := linuxSpool(t)
	record := Record{SchemaVersion: 1, Session: session(), Submission: golden(t)}
	if err := spool.Save(record); err != nil {
		t.Fatal(err)
	}
	if second, err := OpenSpool(spool.dir, 0); err == nil {
		second.Close()
		t.Fatal("two processes owned same spool")
	}
	spool.maxBytes = 1
	if err := spool.Save(record); err == nil {
		t.Fatal("capacity failure ignored")
	}
	path := filepath.Join(spool.dir, recordName(record))
	if err := os.WriteFile(path, []byte(`{"broken":true}`), 0600); err != nil {
		t.Fatal(err)
	}
	dir := spool.dir
	spool.Close()
	if next, err := OpenSpool(dir, 0); err == nil {
		next.Close()
		t.Fatal("corrupt spool ignored")
	}
	if b, err := os.ReadFile(path); err != nil || string(b) != `{"broken":true}` {
		t.Fatal("corrupt recovery material deleted")
	}
}

func TestHandoffRequiresExactLegacyPendingAbsenceAndEmptyCounters(t *testing.T) {
	path := filepath.Join(t.TempDir(), "v1.pending.json")
	if CheckHandoff(path, true) != nil {
		t.Fatal("empty explicit handoff denied")
	}
	if CheckHandoff(path, false) == nil || CheckHandoff("", true) == nil {
		t.Fatal("unproven counter handoff accepted")
	}
	if err := os.WriteFile(path, []byte(`{"BatchID":"old-original"}`), 0600); err != nil {
		t.Fatal(err)
	}
	if CheckHandoff(path, true) == nil {
		t.Fatal("v1 pending transferred to v2")
	}
	if b, err := os.ReadFile(path); err != nil || string(b) != `{"BatchID":"old-original"}` {
		t.Fatal("legacy pending touched")
	}
}

func TestCapturedIntervalCannotBeCountedAgainAfterRestart(t *testing.T) {
	spool := linuxSpool(t)
	transport := &fakeTransport{}
	reporter, err := NewReporter(session(), spool, transport)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := reporter.Capture(1791770400, 1791770460, map[int][2]int64{7: {1, 2}}); err != nil {
		t.Fatal(err)
	}
	if _, err := reporter.Capture(1791770400, 1791770460, map[int][2]int64{7: {1, 2}}); err == nil {
		t.Fatal("one collection interval acquired two batch identities")
	}
	dir := spool.dir
	reporter.Close()
	reopened, err := OpenSpool(dir, 0)
	if err != nil {
		t.Fatal(err)
	}
	defer reopened.Close()
	reporter, err = NewReporter(session(), reopened, transport)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := reporter.Capture(1791770430, 1791770480, map[int][2]int64{7: {1, 2}}); err == nil {
		t.Fatal("restart forgot collection boundary")
	}
	if _, err := reporter.Capture(1791770460, 1791770480, map[int][2]int64{7: {3, 4}}); err != nil {
		t.Fatal("adjacent half-open interval rejected", err)
	}
}
