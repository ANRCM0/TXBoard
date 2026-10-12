//go:build linux

package usagebucket

import (
	"bufio"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
	"testing"
	"time"
)

// The parent kills only its own test helper PID. Each helper writes solely to
// its parent-created t.TempDir; no Service, listener or billing writer starts.
func TestStoreSIGKILLHelper(t *testing.T) {
	if os.Getenv("TX_BUCKET_CRASH_HELPER") != "1" {
		return
	}
	dir, stage := os.Getenv("TX_BUCKET_CRASH_DIR"), os.Getenv("TX_BUCKET_CRASH_STAGE")
	armed := false
	s, err := openStore(dir, StoreOptions{}, func(at string) error {
		if armed && at == stage {
			fmt.Println("BUCKET_CRASH_GATE")
			select {}
		}
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	armed = true
	m := storeMeter(t)
	c, err := NewCoordinator(s, CoordinatorOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if err := c.Add(m); err != nil {
		t.Fatal(err)
	}
	select {}
}

func TestStoreActualSIGKILLAtDurabilityAndAckGates(t *testing.T) {
	for _, stage := range []string{"after_file_sync", "after_dir_sync", "after_head_dir_sync", "before_ack", "after_ack"} {
		t.Run(stage, func(t *testing.T) {
			dir := filepath.Join(t.TempDir(), "journal")
			command := exec.Command(os.Args[0], "-test.run=^TestStoreSIGKILLHelper$", "-test.timeout=30s")
			command.Env = append(os.Environ(), "TX_BUCKET_CRASH_HELPER=1", "TX_BUCKET_CRASH_DIR="+dir, "TX_BUCKET_CRASH_STAGE="+stage)
			stdout, err := command.StdoutPipe()
			if err != nil {
				t.Fatal(err)
			}
			command.Stderr = os.Stderr
			if err := command.Start(); err != nil {
				t.Fatal(err)
			}
			finished := false
			t.Cleanup(func() {
				if !finished {
					_ = command.Process.Kill()
					_ = command.Wait()
				}
			})
			gate := make(chan bool, 1)
			go func() {
				scanner := bufio.NewScanner(stdout)
				for scanner.Scan() {
					if scanner.Text() == "BUCKET_CRASH_GATE" {
						gate <- true
						return
					}
				}
				gate <- false
			}()
			select {
			case ready := <-gate:
				if !ready {
					t.Fatal("helper exited before gate")
				}
			case <-time.After(5 * time.Second):
				t.Fatal("helper gate timed out")
			}
			if err := command.Process.Kill(); err != nil {
				t.Fatal(err)
			}
			if err := command.Wait(); err == nil {
				t.Fatal("helper was not killed")
			}
			if status, ok := command.ProcessState.Sys().(syscall.WaitStatus); !ok || !status.Signaled() || status.Signal() != syscall.SIGKILL {
				t.Fatal("helper did not exit through SIGKILL")
			}
			finished = true
			store, err := OpenStore(dir, StoreOptions{})
			if stage == "before_ack" || stage == "after_ack" {
				if err != nil {
					t.Fatalf("fully durable restart %v", err)
				}
				defer store.Close()
				if store.Status().Buckets != 1 {
					t.Fatal("ACK boundary duplicated/lost durable bucket")
				}
				record, err := store.Read(2)
				if err != nil || record.Bucket == nil || record.Bucket.Events[0].RawCount != 11 {
					t.Fatal("durable original lost")
				}
				begin, _ := store.Read(store.Status().Sequence)
				if !hasStoreReason(begin, "UNCLEAN_PROCESS_BOUNDARY") || !hasStoreReason(begin, "UNSYNCED_TAIL_UNKNOWN") || begin.Authorizes {
					t.Fatal("SIGKILL tail hidden")
				}
			} else if !errors.Is(err, ErrStoreRecovery) {
				t.Fatalf("uncertain kill resumed %v", err)
			}
		})
	}
}
