//go:build linux

package usageobserver

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"reflect"
	"sort"
	"strings"
	"sync"
	"testing"
	"time"
)

func readEvidence(t *testing.T, dir string) []envelope {
	t.Helper()
	paths, err := filepath.Glob(filepath.Join(dir, "*.json"))
	if err != nil {
		t.Fatal(err)
	}
	sort.Strings(paths)
	var result []envelope
	for _, path := range paths {
		data, err := os.ReadFile(path)
		if err != nil {
			t.Fatal(err)
		}
		var item envelope
		if err := json.Unmarshal(data, &item); err != nil {
			t.Fatal(err)
		}
		result = append(result, item)
	}
	return result
}
func reason(record Record, want string) bool {
	for _, r := range record.Reasons {
		if r == want {
			return true
		}
	}
	return false
}
func mustOpen(t *testing.T, dir string) *Observer {
	t.Helper()
	o, err := Open(dir, Options{})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = o.Close() })
	return o
}

func TestRestartRetainsOriginalRecordsAndNewProcessGeneration(t *testing.T) {
	dir := t.TempDir()
	o := mustOpen(t, dir)
	if err := o.Sample(map[int][2]int64{7: {1 << 54, 9}}); err != nil {
		t.Fatal(err)
	}
	if err := o.Close(); err != nil {
		t.Fatal(err)
	}
	original, err := os.ReadFile(filepath.Join(dir, filename(2)))
	if err != nil {
		t.Fatal(err)
	}
	restarted := mustOpen(t, dir)
	records := readEvidence(t, dir)
	if restarted.Status().Generation != 2 || records[3].Record.ProcessID == records[0].Record.ProcessID || records[3].Record.SampleKind != "none" || reason(records[3].Record, "UNCLEAN_PROCESS_BOUNDARY") {
		t.Fatalf("restart records: %+v", records)
	}
	if records[1].Record.Counters[0].Up != "18014398509481984" {
		t.Fatal("large integer changed")
	}
	after, _ := os.ReadFile(filepath.Join(dir, filename(2)))
	if string(after) != string(original) {
		t.Fatal("old evidence overwritten")
	}
	if info, _ := os.Stat(filepath.Join(dir, filename(2))); info.Mode().Perm() != 0600 {
		t.Fatal("evidence permissions")
	}
}

func TestClockRollbackCounterResetAndMissingRemainUnattributable(t *testing.T) {
	dir := t.TempDir()
	stamp := Stamp{time.Unix(200, 0), 10 * time.Second}
	o, err := open(dir, Options{}, func() Stamp { return stamp }, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer o.Close()
	if err := o.Sample(map[int][2]int64{7: {100, 200}, 8: {1, 2}}); err != nil {
		t.Fatal(err)
	}
	stamp = Stamp{time.Unix(100, 0), 5 * time.Second}
	if err := o.Sample(map[int][2]int64{7: {2, -1}}); err != nil {
		t.Fatal(err)
	}
	r := readEvidence(t, dir)[2].Record
	for _, code := range []string{"AUTHORIZATION_BOUNDARY_UNKNOWN", "CLOCK_ROLLBACK", "MONOTONIC_ROLLBACK", "COUNTER_RESET", "NEGATIVE_COUNTER", "COUNTER_MISSING"} {
		if !reason(r, code) {
			t.Fatalf("missing %s: %+v", code, r)
		}
	}
	if r.Attribution != "unattributable" || r.Counters[0].Down != "-1" {
		t.Fatal("reset was reinterpreted as billable bytes")
	}
}

func TestManualAndLifecycleBoundaryKeepOriginalSampleStamp(t *testing.T) {
	dir := t.TempDir()
	stamp := Stamp{time.Unix(100, 0), time.Second}
	o, err := open(dir, Options{}, func() Stamp { return stamp }, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer o.Close()
	if err := o.Sample(map[int][2]int64{7: {100, 200}}); err != nil {
		t.Fatal(err)
	}
	stamp = Stamp{time.Unix(200, 0), 2 * time.Second}
	if err := o.Boundary("KERNEL_RELOAD_BEGIN", true); err != nil {
		t.Fatal(err)
	}
	r := readEvidence(t, dir)[2].Record
	if r.SampleKind != "last_track_snapshot" || r.SampleWallNanos != "100000000000" || r.WallNanos != "200000000000" || r.SampleGeneration != 1 || r.Generation != 2 || !reason(r, "UNSAMPLED_TAIL_UNKNOWN") || !reason(r, "AUTHORIZATION_BOUNDARY_UNKNOWN") {
		t.Fatalf("boundary falsely resampled: %+v", r)
	}
}

func TestInterruptedWritesRecoverWithoutReplacingPublishedEvidence(t *testing.T) {
	for _, stage := range []string{"before_write", "after_file_sync", "after_publish"} {
		t.Run(stage, func(t *testing.T) {
			dir := t.TempDir()
			fail := false
			o, err := open(dir, Options{}, nil, func(at string) error {
				if fail && at == stage {
					return errors.New("injected disk failure")
				}
				return nil
			})
			if err != nil {
				t.Fatal(err)
			}
			if err := o.Sample(map[int][2]int64{7: {100, 200}}); err != nil {
				t.Fatal(err)
			}
			original, _ := os.ReadFile(filepath.Join(dir, filename(2)))
			fail = true
			if err := o.Sample(map[int][2]int64{7: {300, 400}}); err == nil || !o.Status().Degraded {
				t.Fatal("failure acknowledged")
			}
			before := readEvidence(t, dir)
			if err := o.Sample(map[int][2]int64{7: {500, 600}}); err == nil {
				t.Fatal("degraded writer resumed")
			}
			if len(readEvidence(t, dir)) != len(before) {
				t.Fatal("degraded writer changed records")
			}
			_ = o.Close()
			restarted := mustOpen(t, dir)
			_ = restarted
			after, _ := os.ReadFile(filepath.Join(dir, filename(2)))
			if string(after) != string(original) {
				t.Fatal("published evidence overwritten")
			}
			records := readEvidence(t, dir)
			last := records[len(records)-1].Record
			if !reason(last, "UNCLEAN_PROCESS_BOUNDARY") || last.SampleKind != "none" {
				t.Fatalf("unclean restart hidden: %+v", last)
			}
			if stage == "after_file_sync" {
				if !reason(last, "INTERRUPTED_WRITE_RETAINED") {
					t.Fatal("orphan evidence forgotten")
				}
				files, _ := filepath.Glob(filepath.Join(dir, ".pending-*"))
				if len(files) != 1 {
					t.Fatal("orphan removed")
				}
			}
			if stage == "after_publish" && records[2].Record.Counters[0].Up != "300" {
				t.Fatal("uncertain publication overwritten")
			}
		})
	}
}

func TestHistoryTamperingAndMissingRecordFailClosed(t *testing.T) {
	for _, mode := range []string{"tamper", "missing", "symlink", "unexpected"} {
		t.Run(mode, func(t *testing.T) {
			dir := t.TempDir()
			o := mustOpen(t, dir)
			if err := o.Sample(map[int][2]int64{7: {12, 34}}); err != nil {
				t.Fatal(err)
			}
			_ = o.Close()
			path := filepath.Join(dir, filename(2))
			switch mode {
			case "tamper":
				data, _ := os.ReadFile(path)
				data = []byte(strings.Replace(string(data), "\"12\"", "\"13\"", 1))
				if err := os.WriteFile(path, data, 0600); err != nil {
					t.Fatal(err)
				}
			case "missing":
				if err := os.Remove(path); err != nil {
					t.Fatal(err)
				}
			case "symlink":
				data, _ := os.ReadFile(path)
				target := filepath.Join(t.TempDir(), "foreign.json")
				if err := os.WriteFile(target, data, 0600); err != nil {
					t.Fatal(err)
				}
				if err := os.Remove(path); err != nil {
					t.Fatal(err)
				}
				if err := os.Symlink(target, path); err != nil {
					t.Fatal(err)
				}
			case "unexpected":
				if err := os.WriteFile(filepath.Join(dir, "foreign"), []byte("unknown"), 0600); err != nil {
					t.Fatal(err)
				}
			}
			if restarted, err := Open(dir, Options{}); !errors.Is(err, ErrEvidence) {
				if restarted != nil {
					_ = restarted.Close()
				}
				t.Fatalf("invalid history accepted: %v", err)
			}
		})
	}
}

func TestExclusiveWriterAndClosedOwnerCannotAppend(t *testing.T) {
	dir := t.TempDir()
	o := mustOpen(t, dir)
	if other, err := Open(dir, Options{}); err == nil {
		_ = other.Close()
		t.Fatal("second writer admitted")
	}
	if err := o.Close(); err != nil {
		t.Fatal(err)
	}
	replacement := mustOpen(t, dir)
	before := replacement.Status()
	if !errors.Is(o.Sample(map[int][2]int64{7: {9, 9}}), ErrClosed) {
		t.Fatal("closed owner wrote")
	}
	if replacement.Status() != before {
		t.Fatal("replacement writer changed")
	}
}

func TestCapacityNeverDeletesOrReinterpretsOldEvidence(t *testing.T) {
	dir := t.TempDir()
	o, err := Open(dir, Options{MaxBytes: 2048})
	if err != nil {
		t.Fatal(err)
	}
	defer o.Close()
	original, _ := os.ReadFile(filepath.Join(dir, filename(1)))
	for i := 0; i < 10; i++ {
		if err = o.Sample(map[int][2]int64{7: {int64(i), 9}}); err != nil {
			break
		}
	}
	if !errors.Is(err, ErrCapacity) || !o.Status().Degraded {
		t.Fatalf("capacity not bounded: %v", err)
	}
	after, _ := os.ReadFile(filepath.Join(dir, filename(1)))
	if string(after) != string(original) {
		t.Fatal("capacity deleted old evidence")
	}
}

func TestConcurrentSamplesHaveOneDurableOrderedChain(t *testing.T) {
	dir := t.TempDir()
	o := mustOpen(t, dir)
	var wg sync.WaitGroup
	for i := 1; i <= 32; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			if err := o.Sample(map[int][2]int64{7: {int64(i), int64(i)}}); err != nil {
				t.Error(err)
			}
		}(i)
	}
	wg.Wait()
	if err := o.Close(); err != nil {
		t.Fatal(err)
	}
	if len(readEvidence(t, dir)) != 34 {
		t.Fatal("concurrent samples lost")
	}
	restarted := mustOpen(t, dir)
	if restarted.Status().Sequence != 35 {
		t.Fatal("chain restore failed")
	}
}

func TestSampleCopiesCallerCounters(t *testing.T) {
	dir := t.TempDir()
	o := mustOpen(t, dir)
	traffic := map[int][2]int64{7: {123, 456}}
	if err := o.Sample(traffic); err != nil {
		t.Fatal(err)
	}
	traffic[7] = [2]int64{999, 999}
	if err := o.Boundary("USER_UPDATE_BEGIN", true); err != nil {
		t.Fatal(err)
	}
	r := readEvidence(t, dir)
	if !reflect.DeepEqual(r[1].Record.Counters, r[2].Record.Counters) || r[2].Record.Counters[0].Up != "123" {
		t.Fatal("caller mutated immutable sample")
	}
}
