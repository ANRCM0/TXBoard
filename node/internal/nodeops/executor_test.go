package nodeops

import (
	"context"
	"errors"
	"os"
	"strings"
	"testing"

	"github.com/ANRCM0/TX-Node/internal/controlplane"
)

type fakeRuntime struct {
	available bool
	validErr  error
	restartErr error
	reloadErr error
	kernel    string
	running   bool
	logPath   string
	restarts  int
	reloads   int
}

func (f *fakeRuntime) CurrentAvailable() bool { return f.available }
func (f *fakeRuntime) ValidateCurrent() error { return f.validErr }
func (f *fakeRuntime) RestartCurrent() error {
	f.restarts++
	return f.restartErr
}
func (f *fakeRuntime) ReloadCurrent() error {
	f.reloads++
	return f.reloadErr
}
func (f *fakeRuntime) KernelStatus() (string, bool) { return f.kernel, f.running }
func (f *fakeRuntime) SystemInfo() map[string]interface{} {
	return map[string]interface{}{"cpu": float64(12)}
}
func (f *fakeRuntime) ApplicationLogPath() string { return f.logPath }

func TestExecutorCachesReplayBeforeNonIdempotentRestart(t *testing.T) {
	runtime := &fakeRuntime{available: true, kernel: "sing-box", running: true}
	var results []controlplane.OpsResult
	executor := New(runtime, func(result controlplane.OpsResult) {
		results = append(results, result)
	})

	request := &controlplane.OpsRequest{
		RequestID: "ops_01",
		Operation: "ops.kernel.restart",
	}
	executor.Handle(context.Background(), request)
	executor.Handle(context.Background(), request)

	if runtime.restarts != 1 {
		t.Fatalf("restart executed %d times, want 1", runtime.restarts)
	}
	if len(results) != 2 || !results[0].OK || !results[1].OK {
		t.Fatalf("unexpected results: %#v", results)
	}
	if results[0].RequestID != results[1].RequestID {
		t.Fatalf("replayed result changed request id: %#v", results)
	}
}

func TestExecutorPreservesTypedFailureCodes(t *testing.T) {
	runtime := &fakeRuntime{
		available: true,
		validErr: errors.New("bad config"),
		kernel: "sing-box",
	}
	var got controlplane.OpsResult
	executor := New(runtime, func(result controlplane.OpsResult) { got = result })

	executor.Handle(context.Background(), &controlplane.OpsRequest{
		RequestID: "ops_02",
		Operation: "ops.config.reload",
	})

	if got.OK || got.ErrorCode != "config_invalid" || got.Message != "bad config" {
		t.Fatalf("unexpected typed failure: %#v", got)
	}
	if runtime.reloads != 0 {
		t.Fatal("reload must not execute after validation failure")
	}
}

func TestNetworkTargetValidation(t *testing.T) {
	if got, err := networkTarget(map[string]interface{}{"target": "node.example.com"}); err != nil || got != "node.example.com" {
		t.Fatalf("unexpected valid target result: %q, %v", got, err)
	}
	for _, target := range []string{"", "https://example.com", "example.com/path", "bad host"} {
		if _, err := networkTarget(map[string]interface{}{"target": target}); err == nil {
			t.Fatalf("expected target %q to be rejected", target)
		}
	}
}

func TestPortValidation(t *testing.T) {
	for _, value := range []interface{}{1, 443, 65535} {
		if _, err := port(map[string]interface{}{"port": value}); err != nil {
			t.Fatalf("expected port %v to be accepted: %v", value, err)
		}
	}
	for _, value := range []interface{}{0, 65536, "abc"} {
		if _, err := port(map[string]interface{}{"port": value}); err == nil {
			t.Fatalf("expected port %v to be rejected", value)
		}
	}
}

func TestTailApplicationLogIsBoundedAndRedacted(t *testing.T) {
	file, err := os.CreateTemp(t.TempDir(), "tx-node-*.log")
	if err != nil {
		t.Fatal(err)
	}
	path := file.Name()

	for i := 0; i < 10; i++ {
		if _, err := file.WriteString("12:00:00 INFO [core] harmless line\n"); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := file.WriteString("12:00:01 INFO [core] token=super-secret password=hunter2 uuid=00000000-0000-0000-0000-000000000001\n"); err != nil {
		t.Fatal(err)
	}
	if _, err := file.WriteString("12:00:02 INFO [core] Authorization: Bearer abc.def.ghi\n"); err != nil {
		t.Fatal(err)
	}
	if err := file.Close(); err != nil {
		t.Fatal(err)
	}

	result, err := tailApplicationLog(path, map[string]interface{}{
		"source":    "application",
		"lines":     3,
		"max_bytes": 4096,
	})
	if err != nil {
		t.Fatalf("tailApplicationLog: %v", err)
	}

	content, _ := result["content"].(string)
	if strings.Contains(content, "super-secret") ||
		strings.Contains(content, "hunter2") ||
		strings.Contains(content, "abc.def.ghi") ||
		strings.Contains(content, "00000000-0000-0000-0000-000000000001") {
		t.Fatalf("sensitive data was not redacted: %s", content)
	}
	if !strings.Contains(content, "[REDACTED]") {
		t.Fatalf("expected redaction marker, got: %s", content)
	}
	if got := len(strings.Split(content, "\n")); got > 3 {
		t.Fatalf("expected at most 3 lines, got %d", got)
	}
}

func TestTailApplicationLogRejectsStdoutAndOversizedBounds(t *testing.T) {
	if _, err := tailApplicationLog("stdout", map[string]interface{}{"source": "application", "lines": 10}); err == nil {
		t.Fatal("expected stdout log source to be unavailable")
	}
	if _, err := boundedInt(map[string]interface{}{"lines": 201}, "lines", 100, 1, 200); err == nil {
		t.Fatal("expected oversized line count to be rejected")
	}
}
