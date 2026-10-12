package main

import (
	"context"
	"errors"
	"os"
	"sync"
	"time"
	"path/filepath"
	"testing"
)

func TestResolveConfigPathWithFallback(t *testing.T) {
	t.Run("canonical path wins when present", func(t *testing.T) {
		dir := t.TempDir()
		canonical := filepath.Join(dir, "txnode", "config.yml")
		legacy := filepath.Join(dir, "xboard-node", "config.yml")
		mustWriteConfigPath(t, canonical)
		mustWriteConfigPath(t, legacy)

		if got := resolveConfigPathWithFallback(canonical, canonical, legacy); got != canonical {
			t.Fatalf("resolveConfigPathWithFallback() = %q, want canonical %q", got, canonical)
		}
	})

	t.Run("legacy path is fallback when canonical is absent", func(t *testing.T) {
		dir := t.TempDir()
		canonical := filepath.Join(dir, "txnode", "config.yml")
		legacy := filepath.Join(dir, "xboard-node", "config.yml")
		mustWriteConfigPath(t, legacy)

		if got := resolveConfigPathWithFallback(canonical, canonical, legacy); got != legacy {
			t.Fatalf("resolveConfigPathWithFallback() = %q, want legacy %q", got, legacy)
		}
	})

	t.Run("canonical remains selected when neither path exists", func(t *testing.T) {
		dir := t.TempDir()
		canonical := filepath.Join(dir, "txnode", "config.yml")
		legacy := filepath.Join(dir, "xboard-node", "config.yml")

		if got := resolveConfigPathWithFallback(canonical, canonical, legacy); got != canonical {
			t.Fatalf("resolveConfigPathWithFallback() = %q, want canonical %q", got, canonical)
		}
	})

	t.Run("custom config paths never fall back", func(t *testing.T) {
		dir := t.TempDir()
		canonical := filepath.Join(dir, "txnode", "config.yml")
		legacy := filepath.Join(dir, "xboard-node", "config.yml")
		custom := filepath.Join(dir, "custom.yml")
		mustWriteConfigPath(t, legacy)

		if got := resolveConfigPathWithFallback(custom, canonical, legacy); got != custom {
			t.Fatalf("resolveConfigPathWithFallback() = %q, want custom %q", got, custom)
		}
	})
}

func mustWriteConfigPath(t *testing.T, path string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte("test: true\n"), 0o600); err != nil {
		t.Fatal(err)
	}
}

// More node workers may fail than there are top-level instances. All must exit
// even when the main goroutine cannot consume errors until workers finish.
func TestCancelOnServiceErrorNeverBlocksWorkers(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	errorsCh := make(chan error, 1)
	var workers sync.WaitGroup
	for i := 0; i < 64; i++ {
		workers.Add(1)
		go func() {
			defer workers.Done()
			cancelOnServiceError(errorsCh, cancel, errors.New("node failed"))
		}()
	}
	done := make(chan struct{})
	go func() { workers.Wait(); close(done) }()
	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("workers blocked reporting more errors than the channel can hold")
	}
	select {
	case <-ctx.Done():
	default:
		t.Fatal("a fatal node error must cancel all instances")
	}
	if got := <-errorsCh; got == nil {
		t.Fatal("expected the first fatal error")
	}
	select {
	case <-errorsCh:
		t.Fatal("fatal error channel should retain only one error")
	default:
	}
}
