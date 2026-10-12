package reporting

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
)

// batchSpool contains one immutable unacknowledged report. It is written
// atomically and fsynced BEFORE HTTP delivery is attempted.
type batchSpool struct {
	path string
}

func openBatchSpool(path string) (*batchSpool, *Batch, error) {
	if path == "" {
		return nil, nil, errors.New("empty pending batch spool path")
	}
	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, nil, fmt.Errorf("create spool directory: %w", err)
	}
	// Fail early if the directory is read-only, instead of acknowledging
	// traffic using a silently degraded in-memory path.
	probe, err := os.CreateTemp(dir, ".traffic-probe-*")
	if err != nil {
		return nil, nil, fmt.Errorf("spool directory not writable: %w", err)
	}
	name := probe.Name()
	_ = probe.Close()
	_ = os.Remove(name)

	s := &batchSpool{path: path}
	payload, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return s, nil, nil
	}
	if err != nil {
		return nil, nil, fmt.Errorf("load pending batch: %w", err)
	}
	var batch Batch
	if err := json.Unmarshal(payload, &batch); err != nil {
		return nil, nil, fmt.Errorf("corrupt pending batch spool: %w", err)
	}
	if batch.Payload.BatchID == "" || len(batch.Payload.Traffic) == 0 {
		return nil, nil, errors.New("invalid pending batch spool (missing ID/traffic)")
	}
	return s, &batch, nil
}

func (s *batchSpool) save(batch Batch) error {
	dir := filepath.Dir(s.path)
	encoded, err := json.Marshal(batch)
	if err != nil {
		return err
	}
	tmp, err := os.CreateTemp(dir, ".traffic-write-*")
	if err != nil {
		return err
	}
	name := tmp.Name()
	defer os.Remove(name)
	if err := tmp.Chmod(0o600); err != nil {
		_ = tmp.Close()
		return err
	}
	if _, err = tmp.Write(encoded); err != nil {
		_ = tmp.Close()
		return err
	}
	if err = tmp.Sync(); err != nil {
		_ = tmp.Close()
		return err
	}
	if err = tmp.Close(); err != nil {
		return err
	}
	if err = os.Rename(name, s.path); err != nil {
		return err
	}
	if err = syncDirectory(dir); err != nil {
		// Do not deliver an unconfirmed durable write. Remove the
		// uncommitted record before restoring bytes to the tracker.
		_ = os.Remove(s.path)
		return err
	}
	return nil
}

func (s *batchSpool) clear() error {
	if err := os.Remove(s.path); err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}
	return syncDirectory(filepath.Dir(s.path))
}

func syncDirectory(path string) error {
	d, err := os.Open(path)
	if err != nil {
		return err
	}
	defer d.Close()
	return d.Sync()
}
