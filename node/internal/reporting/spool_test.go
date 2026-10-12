package reporting

import (
	"errors"
	"os"
	"path/filepath"
	"testing"

	"github.com/ANRCM0/TX-Node/internal/controlplane"
)

func TestDurableSpoolReplaysUnacknowledgedPayloadAfterRestart(t *testing.T) {
	path := filepath.Join(t.TempDir(), "traffic.pending.json")
	sink := &fakeSink{supported: true, err: errors.New("acknowledgement lost")}
	first, err := NewDurable(sink, path)
	if err != nil {
		t.Fatal(err)
	}
	prepared := 0
	batch, err := first.nextBatch(func() Batch {
		prepared++
		return Batch{Payload: controlplane.ReportPayload{Traffic: map[int][2]int64{7: {12, 34}}}}
	})
	if err != nil || batch.Payload.BatchID == "" || prepared != 1 {
		t.Fatalf("spool first batch: %#v err=%v", batch, err)
	}
	if _, err := os.Stat(path); err != nil {
		t.Fatalf("batch not durably saved: %v", err)
	}
	afterRestart, err := NewDurable(sink, path)
	if err != nil {
		t.Fatal(err)
	}
	replay, err := afterRestart.nextBatch(func() Batch {
		t.Fatal("must never replace an unacknowledged persisted batch")
		return Batch{}
	})
	if err != nil || replay.Payload.BatchID != batch.Payload.BatchID || replay.Payload.Traffic[7] != [2]int64{12, 34} {
		t.Fatalf("restored wrong batch: %#v err=%v", replay, err)
	}
	if err := afterRestart.acknowledge(replay); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(path); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("acknowledged spool still present: %v", err)
	}
}

func TestCorruptDurableSpoolFailsClosed(t *testing.T) {
	path := filepath.Join(t.TempDir(), "bad.pending.json")
	if err := os.WriteFile(path, []byte("truncated-json"), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := NewDurable(&fakeSink{supported: true}, path); err == nil {
		t.Fatal("corrupt spool must fail startup rather than lose traffic")
	}
}
