// Package usageobserver preserves local runtime observations. Its records are
// deliberately unattributable: cumulative v1 meters cannot prove authorization,
// Terms, tariff or usage-period boundaries. This package has no network writer.
package usageobserver

import (
	"bytes"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
)

const DefaultMaxBytes int64 = 64 << 20
const maxFiles = 4096
const maxRecordBytes = 4 << 20

var (
	ErrCapacity = errors.New("usage observer capacity reached")
	ErrEvidence = errors.New("usage observer evidence invalid")
	ErrClosed   = errors.New("usage observer closed")
)

type Counter struct {
	UserID string `json:"user_id"`
	Up     string `json:"raw_up_cumulative"`
	Down   string `json:"raw_down_cumulative"`
}

// Stamp's elapsed time is monotonic within one process only.
type Stamp struct {
	Wall    time.Time
	Elapsed time.Duration
}

type Record struct {
	Version            int       `json:"version"`
	Sequence           uint64    `json:"sequence"`
	PreviousDigest     string    `json:"previous_digest"`
	ProcessID          string    `json:"process_id"`
	Generation         uint64    `json:"observation_generation"`
	Event              string    `json:"event"`
	WallNanos          string    `json:"wall_unix_nanos"`
	ElapsedNanos       string    `json:"process_elapsed_nanos"`
	SampleKind         string    `json:"sample_kind"`
	SampleWallNanos    string    `json:"sample_wall_unix_nanos,omitempty"`
	SampleElapsedNanos string    `json:"sample_process_elapsed_nanos,omitempty"`
	SampleGeneration   uint64    `json:"sample_observation_generation,omitempty"`
	Counters           []Counter `json:"counters"`
	Attribution        string    `json:"attribution"`
	Reasons            []string  `json:"reasons"`
}

type envelope struct {
	Record Record `json:"record"`
	Digest string `json:"digest"`
}
type Options struct{ MaxBytes int64 }
type Status struct {
	Sequence   uint64
	Generation uint64
	Bytes      int64
	Degraded   bool
	Closed     bool
}

type Observer struct {
	mu         sync.Mutex
	dir        string
	lock       *os.File
	maxBytes   int64
	usedBytes  int64
	files      int
	sequence   uint64
	generation uint64
	digest     string
	processID  string
	last       *Record
	lastSample *Record
	clock      func() Stamp
	fault      func(string) error // instance-local failure injection; never a global hook
	failed     error
	closed     bool
}

func Open(dir string, options Options) (*Observer, error) {
	return open(dir, options, nil, nil)
}

func open(dir string, options Options, clock func() Stamp, fault func(string) error) (*Observer, error) {
	if dir == "" {
		return nil, ErrEvidence
	}
	if options.MaxBytes == 0 {
		options.MaxBytes = DefaultMaxBytes
	}
	if options.MaxBytes < 1024 || options.MaxBytes > 1<<40 {
		return nil, ErrCapacity
	}
	if err := durableDirectory(dir); err != nil {
		return nil, err
	}
	info, err := os.Lstat(dir)
	if err != nil || !info.IsDir() || info.Mode()&os.ModeSymlink != 0 {
		return nil, ErrEvidence
	}
	lock, err := lockDirectory(dir)
	if err != nil {
		return nil, err
	}
	o := &Observer{dir: dir, lock: lock, maxBytes: options.MaxBytes, fault: fault}
	success := false
	defer func() {
		if !success {
			_ = lock.Close()
		}
	}()
	if clock == nil {
		start := time.Now()
		clock = func() Stamp { return Stamp{Wall: time.Now(), Elapsed: time.Since(start)} }
	}
	o.clock = clock
	var id [16]byte
	if _, err := rand.Read(id[:]); err != nil {
		return nil, err
	}
	o.processID = hex.EncodeToString(id[:])
	interrupted, err := o.restore()
	if err != nil {
		return nil, err
	}
	if o.generation == ^uint64(0) {
		return nil, ErrEvidence
	}
	o.generation++
	reasons := []string{"PROCESS_BOUNDARY_UNKNOWN"}
	if o.last != nil && o.last.Event != "PROCESS_END" {
		reasons = append(reasons, "UNCLEAN_PROCESS_BOUNDARY")
	}
	if interrupted {
		reasons = append(reasons, "INTERRUPTED_WRITE_RETAINED")
	}
	// No previous-process sample can establish a new-process meter baseline.
	if err := o.append("PROCESS_BEGIN", nil, reasons); err != nil {
		return nil, err
	}
	success = true
	return o, nil
}

// Sync every newly created directory entry, including a new configured root.
// File-directory fsync alone would not persist its parent's mkdir operation.
func durableDirectory(dir string) error {
	info, err := os.Lstat(dir)
	if err == nil {
		if !info.IsDir() || info.Mode()&os.ModeSymlink != 0 {
			return ErrEvidence
		}
		return nil
	}
	if !os.IsNotExist(err) {
		return err
	}
	parent := filepath.Dir(dir)
	if parent == dir {
		return ErrEvidence
	}
	if err := durableDirectory(parent); err != nil {
		return err
	}
	if err := os.Mkdir(dir, 0700); err != nil && !os.IsExist(err) {
		return err
	}
	f, err := os.Open(parent)
	if err != nil {
		return err
	}
	defer f.Close()
	return f.Sync()
}

func (o *Observer) restore() (bool, error) {
	dir, err := os.Open(o.dir)
	if err != nil {
		return false, err
	}
	defer dir.Close()
	entries, err := dir.ReadDir(maxFiles + 2)
	if err != nil && err != io.EOF {
		return false, err
	}
	if len(entries) > maxFiles+1 {
		return false, ErrCapacity
	}
	sort.Slice(entries, func(i, j int) bool { return entries[i].Name() < entries[j].Name() })
	interrupted := false
	for _, entry := range entries {
		if entry.Name() == ".observer.lock" {
			continue
		}
		info, err := entry.Info()
		if err != nil || !info.Mode().IsRegular() {
			return false, ErrEvidence
		}
		o.usedBytes += info.Size()
		o.files++
		if o.usedBytes > o.maxBytes || o.files >= maxFiles {
			return false, ErrCapacity
		}
		if strings.HasPrefix(entry.Name(), ".pending-") {
			interrupted = true
			continue
		}
		if entry.Name() != filename(o.sequence+1) || info.Size() > maxRecordBytes {
			return false, ErrEvidence
		}
		data, err := os.ReadFile(filepath.Join(o.dir, entry.Name()))
		if err != nil {
			return false, err
		}
		var e envelope
		decoder := json.NewDecoder(bytes.NewReader(data))
		decoder.DisallowUnknownFields()
		if decoder.Decode(&e) != nil {
			return false, ErrEvidence
		}
		canonical, err := json.Marshal(e)
		if err != nil || !bytes.Equal(data, canonical) {
			return false, ErrEvidence
		}
		r := e.Record
		payload, _ := json.Marshal(r)
		digest := sha256.Sum256(payload)
		if e.Digest != hex.EncodeToString(digest[:]) || r.Version != 1 || r.Sequence != o.sequence+1 || r.PreviousDigest != o.digest || r.Attribution != "unattributable" || r.Generation == 0 || r.Generation < o.generation {
			return false, ErrEvidence
		}
		// Immutable records use one restricted schema; no grants or secrets.
		if err := validateRecord(r); err != nil {
			return false, err
		}
		o.sequence = r.Sequence
		o.generation = r.Generation
		o.digest = e.Digest
		o.last = &r
	}
	return interrupted, nil
}

func filename(sequence uint64) string { return fmt.Sprintf("%020d.json", sequence) }

func validateRecord(r Record) error {
	if len(r.ProcessID) != 32 || !validEvent(r.Event) || len(r.Counters) > 10000 || len(r.Reasons) == 0 {
		return ErrEvidence
	}
	if _, err := hex.DecodeString(r.ProcessID); err != nil {
		return ErrEvidence
	}
	if _, err := strconv.ParseInt(r.WallNanos, 10, 64); err != nil {
		return ErrEvidence
	}
	if _, err := strconv.ParseInt(r.ElapsedNanos, 10, 64); err != nil {
		return ErrEvidence
	}
	if r.SampleKind != "none" && r.SampleKind != "current_track_sample" && r.SampleKind != "last_track_snapshot" {
		return ErrEvidence
	}
	if r.SampleKind == "none" {
		if len(r.Counters) != 0 || r.SampleGeneration != 0 || r.SampleWallNanos != "" || r.SampleElapsedNanos != "" {
			return ErrEvidence
		}
	} else {
		if r.SampleGeneration == 0 || r.SampleGeneration > r.Generation {
			return ErrEvidence
		}
		if _, err := strconv.ParseInt(r.SampleWallNanos, 10, 64); err != nil {
			return ErrEvidence
		}
		if _, err := strconv.ParseInt(r.SampleElapsedNanos, 10, 64); err != nil {
			return ErrEvidence
		}
	}
	last := int64(0)
	for _, counter := range r.Counters {
		id, err := strconv.ParseInt(counter.UserID, 10, 64)
		if err != nil || id <= last {
			return ErrEvidence
		}
		last = id
		if _, err := strconv.ParseInt(counter.Up, 10, 64); err != nil {
			return ErrEvidence
		}
		if _, err := strconv.ParseInt(counter.Down, 10, 64); err != nil {
			return ErrEvidence
		}
	}
	return nil
}

func validEvent(event string) bool {
	switch event {
	case "PROCESS_BEGIN", "PROCESS_END", "TRACK", "TRACK_ERROR", "KERNEL_START_BEGIN", "KERNEL_START_OK", "KERNEL_START_FAILED", "KERNEL_RELOAD_BEGIN", "KERNEL_RELOAD_OK", "KERNEL_RELOAD_FAILED", "USER_UPDATE_BEGIN", "USER_UPDATE_END", "USER_DELTA_BEGIN", "USER_DELTA_END", "KERNEL_STOP_BEGIN", "KERNEL_STOP_END":
		return true
	}
	return false
}

func (o *Observer) Sample(traffic map[int][2]int64) error {
	o.mu.Lock()
	defer o.mu.Unlock()
	return o.append("TRACK", traffic, nil)
}

// Boundary advances a local observation generation. It does not assert that a
// kernel counter, authorization lease or Core source epoch changed at this time.
func (o *Observer) Boundary(event string, advance bool) error {
	o.mu.Lock()
	defer o.mu.Unlock()
	if o.closed {
		return ErrClosed
	}
	if o.failed != nil {
		return o.failed
	}
	if !validEvent(event) || event == "TRACK" || event == "PROCESS_BEGIN" || event == "PROCESS_END" {
		return ErrEvidence
	}
	if advance {
		if o.generation == ^uint64(0) {
			o.failed = ErrEvidence
			return o.failed
		}
		o.generation++
	}
	return o.append(event, nil, []string{"LIFECYCLE_BOUNDARY_UNKNOWN", "UNSAMPLED_TAIL_UNKNOWN"})
}

func (o *Observer) append(event string, traffic map[int][2]int64, reasons []string) error {
	if o.closed {
		return ErrClosed
	}
	if o.failed != nil {
		return o.failed
	}
	if o.sequence == ^uint64(0) {
		o.failed = ErrEvidence
		return o.failed
	}
	stamp := o.clock()
	r := Record{Version: 1, Sequence: o.sequence + 1, PreviousDigest: o.digest, ProcessID: o.processID, Generation: o.generation, Event: event, WallNanos: strconv.FormatInt(stamp.Wall.UnixNano(), 10), ElapsedNanos: strconv.FormatInt(int64(stamp.Elapsed), 10), SampleKind: "none", Counters: []Counter{}, Attribution: "unattributable", Reasons: []string{"AUTHORIZATION_BOUNDARY_UNKNOWN"}}
	r.Reasons = append(r.Reasons, reasons...)
	if o.last != nil && o.last.ProcessID == o.processID {
		previousWall, _ := strconv.ParseInt(o.last.WallNanos, 10, 64)
		previousElapsed, _ := strconv.ParseInt(o.last.ElapsedNanos, 10, 64)
		if stamp.Wall.UnixNano() < previousWall {
			r.Reasons = append(r.Reasons, "CLOCK_ROLLBACK")
		}
		if int64(stamp.Elapsed) < previousElapsed {
			r.Reasons = append(r.Reasons, "MONOTONIC_ROLLBACK")
		}
	}
	if stamp.Elapsed < 0 {
		r.Reasons = append(r.Reasons, "MONOTONIC_ROLLBACK")
	}
	if event == "TRACK" {
		if len(traffic) > 10000 {
			o.failed = ErrCapacity
			return o.failed
		}
		ids := make([]int, 0, len(traffic))
		for id := range traffic {
			if id <= 0 {
				o.failed = ErrEvidence
				return o.failed
			}
			ids = append(ids, id)
		}
		sort.Ints(ids)
		previous := make(map[string]Counter)
		if o.lastSample != nil {
			for _, counter := range o.lastSample.Counters {
				previous[counter.UserID] = counter
			}
		}
		for _, id := range ids {
			value := traffic[id]
			key := strconv.Itoa(id)
			r.Counters = append(r.Counters, Counter{key, strconv.FormatInt(value[0], 10), strconv.FormatInt(value[1], 10)})
			if value[0] < 0 || value[1] < 0 {
				r.Reasons = append(r.Reasons, "NEGATIVE_COUNTER")
			}
			if prev, ok := previous[key]; ok {
				up, _ := strconv.ParseInt(prev.Up, 10, 64)
				down, _ := strconv.ParseInt(prev.Down, 10, 64)
				if value[0] < up || value[1] < down {
					r.Reasons = append(r.Reasons, "COUNTER_RESET")
				}
				delete(previous, key)
			}
		}
		if len(previous) > 0 {
			r.Reasons = append(r.Reasons, "COUNTER_MISSING")
		}
		r.SampleKind = "current_track_sample"
		r.SampleWallNanos = r.WallNanos
		r.SampleElapsedNanos = r.ElapsedNanos
		r.SampleGeneration = r.Generation
	} else if o.lastSample != nil {
		r.SampleKind = "last_track_snapshot"
		r.Counters = append([]Counter{}, o.lastSample.Counters...)
		r.SampleWallNanos = o.lastSample.SampleWallNanos
		r.SampleElapsedNanos = o.lastSample.SampleElapsedNanos
		r.SampleGeneration = o.lastSample.SampleGeneration
	}
	sort.Strings(r.Reasons)
	r.Reasons = unique(r.Reasons)
	payload, _ := json.Marshal(r)
	sum := sha256.Sum256(payload)
	e := envelope{r, hex.EncodeToString(sum[:])}
	data, err := json.Marshal(e)
	if err == nil {
		err = o.persist(data, r.Sequence)
	}
	if err != nil {
		o.failed = err
		return err
	}
	o.sequence = r.Sequence
	o.digest = e.Digest
	o.last = &r
	if event == "TRACK" {
		o.lastSample = &r
	}
	return nil
}

func unique(values []string) []string {
	result := values[:0]
	for _, value := range values {
		if len(result) == 0 || result[len(result)-1] != value {
			result = append(result, value)
		}
	}
	return result
}

func (o *Observer) persist(data []byte, sequence uint64) error {
	if len(data) > maxRecordBytes || o.usedBytes+int64(len(data)) > o.maxBytes || o.files+1 >= maxFiles {
		return ErrCapacity
	}
	check := func(stage string) error {
		if o.fault != nil {
			return o.fault(stage)
		}
		return nil
	}
	if err := check("before_write"); err != nil {
		return err
	}
	f, err := os.CreateTemp(o.dir, ".pending-")
	if err != nil {
		return err
	}
	// Incomplete writes remain as evidence. No automatic cleanup or retry can
	// silently replace a record whose publication/directory sync was uncertain.
	defer f.Close()
	if err := f.Chmod(0600); err != nil {
		return err
	}
	if _, err := f.Write(data); err != nil {
		return err
	}
	if err := f.Sync(); err != nil {
		return err
	}
	if err := check("after_file_sync"); err != nil {
		return err
	}
	if err := f.Close(); err != nil {
		return err
	}
	// Hard-link publication refuses to overwrite an existing immutable record.
	if err := os.Link(f.Name(), filepath.Join(o.dir, filename(sequence))); err != nil {
		return err
	}
	if err := os.Remove(f.Name()); err != nil {
		return err
	}
	if err := check("after_publish"); err != nil {
		return err
	}
	dir, err := os.Open(o.dir)
	if err != nil {
		return err
	}
	defer dir.Close()
	if err := dir.Sync(); err != nil {
		return err
	}
	o.usedBytes += int64(len(data))
	o.files++
	return nil
}

func (o *Observer) Status() Status {
	o.mu.Lock()
	defer o.mu.Unlock()
	return Status{o.sequence, o.generation, o.usedBytes, o.failed != nil, o.closed}
}

func (o *Observer) Close() error {
	o.mu.Lock()
	defer o.mu.Unlock()
	if o.closed {
		return o.failed
	}
	err := o.append("PROCESS_END", nil, []string{"UNSAMPLED_TAIL_UNKNOWN"})
	o.closed = true
	if closeErr := o.lock.Close(); err == nil {
		err = closeErr
	}
	return err
}
