package nodeops

import (
	"context"
	"fmt"
	"io"
	"net"
	"os"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/ANRCM0/TX-Node/internal/controlplane"
)

const maxResultCache = 128

// Runtime is the deliberately narrow boundary exposed by the node Service to
// typed operations. The executor can inspect/restart/reload the current node
// runtime, but it does not own ControlPlane synchronization, kernel creation,
// user state, certificate management, deployment, or arbitrary host access.
type Runtime interface {
	CurrentAvailable() bool
	ValidateCurrent() error
	RestartCurrent() error
	ReloadCurrent() error
	KernelStatus() (name string, running bool)
	SystemInfo() map[string]interface{}
	ApplicationLogPath() string
}

// Sender reports a bounded typed result through the existing ControlPlane
// result channel. The concrete WebSocket transport remains owned by Service.
type Sender func(controlplane.OpsResult)

// Executor owns Node Ops dispatch and replay protection. It is intentionally
// independent from Service orchestration so adding an operation cannot silently
// grow the Service god-object or gain access to unrelated runtime state.
type Executor struct {
	runtime Runtime
	send    Sender

	mu      sync.Mutex
	results map[string]controlplane.OpsResult
	order   []string
}

func New(runtime Runtime, send Sender) *Executor {
	return &Executor{
		runtime: runtime,
		send:    send,
		results: make(map[string]controlplane.OpsResult),
	}
}

func (e *Executor) Handle(ctx context.Context, request *controlplane.OpsRequest) {
	if request == nil || request.RequestID == "" || e == nil || e.runtime == nil {
		return
	}
	if cached, found := e.cachedResult(request.RequestID); found {
		e.sendResult(cached)
		return
	}

	ok := func(result map[string]interface{}) {
		e.completeResult(controlplane.OpsResult{
			RequestID: request.RequestID,
			Operation: request.Operation,
			OK:        true,
			Result:    result,
		})
	}
	fail := func(code string, err error) {
		message := ""
		if err != nil {
			message = err.Error()
		}
		e.completeResult(controlplane.OpsResult{
			RequestID: request.RequestID,
			Operation: request.Operation,
			OK:        false,
			ErrorCode: code,
			Message:   message,
		})
	}

	switch request.Operation {
	case "ops.kernel.status":
		name, running := e.runtime.KernelStatus()
		ok(map[string]interface{}{
			"kernel":  name,
			"running": running,
		})

	case "ops.kernel.restart":
		if !e.runtime.CurrentAvailable() {
			fail("config_unavailable", fmt.Errorf("node config is not available"))
			return
		}
		if err := e.runtime.ValidateCurrent(); err != nil {
			fail("config_invalid", err)
			return
		}
		if err := e.runtime.RestartCurrent(); err != nil {
			fail("kernel_restart_failed", err)
			return
		}
		name, running := e.runtime.KernelStatus()
		ok(map[string]interface{}{
			"kernel":         name,
			"kernel_running": running,
		})

	case "ops.config.validate":
		if !e.runtime.CurrentAvailable() {
			fail("config_unavailable", fmt.Errorf("node config is not available"))
			return
		}
		if err := e.runtime.ValidateCurrent(); err != nil {
			fail("config_invalid", err)
			return
		}
		ok(map[string]interface{}{"valid": true})

	case "ops.config.reload":
		if !e.runtime.CurrentAvailable() {
			fail("config_unavailable", fmt.Errorf("node config is not available"))
			return
		}
		if err := e.runtime.ValidateCurrent(); err != nil {
			fail("config_invalid", err)
			return
		}
		if err := e.runtime.ReloadCurrent(); err != nil {
			fail("config_reload_failed", err)
			return
		}
		_, running := e.runtime.KernelStatus()
		ok(map[string]interface{}{
			"reloaded":       true,
			"kernel_running": running,
		})

	case "ops.system.info":
		metrics := e.runtime.SystemInfo()
		if metrics == nil {
			metrics = map[string]interface{}{}
		}
		name, _ := e.runtime.KernelStatus()
		metrics["kernel"] = name
		ok(metrics)

	case "ops.network.dns":
		target, err := networkTarget(request.Args)
		if err != nil {
			fail("invalid_target", err)
			return
		}
		opCtx, cancel := context.WithTimeout(ctx, 5*time.Second)
		addresses, err := net.DefaultResolver.LookupHost(opCtx, target)
		cancel()
		if err != nil {
			fail("dns_lookup_failed", err)
			return
		}
		if len(addresses) > 16 {
			addresses = addresses[:16]
		}
		ok(map[string]interface{}{"target": target, "addresses": addresses})

	case "ops.logs.tail":
		result, err := tailApplicationLog(e.runtime.ApplicationLogPath(), request.Args)
		if err != nil {
			fail("log_tail_failed", err)
			return
		}
		ok(result)

	case "ops.network.port_check":
		target, err := networkTarget(request.Args)
		if err != nil {
			fail("invalid_target", err)
			return
		}
		port, err := port(request.Args)
		if err != nil {
			fail("invalid_port", err)
			return
		}
		dialer := net.Dialer{Timeout: 5 * time.Second}
		opCtx, cancel := context.WithTimeout(ctx, 5*time.Second)
		conn, err := dialer.DialContext(opCtx, "tcp", net.JoinHostPort(target, strconv.Itoa(port)))
		cancel()
		if err != nil {
			fail("port_check_failed", err)
			return
		}
		_ = conn.Close()
		ok(map[string]interface{}{"target": target, "port": port, "reachable": true})

	default:
		fail("unsupported_operation", fmt.Errorf("unsupported operation: %s", request.Operation))
	}
}

func (e *Executor) cachedResult(requestID string) (controlplane.OpsResult, bool) {
	e.mu.Lock()
	defer e.mu.Unlock()
	result, ok := e.results[requestID]
	return result, ok
}

func (e *Executor) completeResult(result controlplane.OpsResult) {
	e.mu.Lock()
	if existing, ok := e.results[result.RequestID]; ok {
		result = existing
	} else {
		e.results[result.RequestID] = result
		e.order = append(e.order, result.RequestID)
		if len(e.order) > maxResultCache {
			oldest := e.order[0]
			e.order = e.order[1:]
			delete(e.results, oldest)
		}
	}
	e.mu.Unlock()
	e.sendResult(result)
}

func (e *Executor) sendResult(result controlplane.OpsResult) {
	if e.send != nil {
		e.send(result)
	}
}

var authorizationPattern = regexp.MustCompile(`(?i)(authorization:\s*bearer\s+)[^\s]+`)
var secretPattern = regexp.MustCompile(`(?i)("?(token|password|passwd|secret|private_key|api_key|credential|uuid)"?\s*[:=]\s*)("[^"]*"|'[^']*'|[^\s,}]+)`)

func tailApplicationLog(output string, args map[string]interface{}) (map[string]interface{}, error) {
	source := strings.ToLower(strings.TrimSpace(fmt.Sprint(args["source"])))
	if source == "" || source == "<nil>" {
		source = "application"
	}
	if source != "application" {
		return nil, fmt.Errorf("unsupported log source")
	}

	output = strings.TrimSpace(output)
	if output == "" || output == "stdout" || output == "stderr" {
		return nil, fmt.Errorf("application log is not configured as a file")
	}

	lines, err := boundedInt(args, "lines", 100, 1, 200)
	if err != nil {
		return nil, err
	}
	maxBytes, err := boundedInt(args, "max_bytes", 65536, 1024, 65536)
	if err != nil {
		return nil, err
	}

	content, truncated, err := tailLogFile(output, lines, maxBytes)
	if err != nil {
		return nil, err
	}

	return map[string]interface{}{
		"source":    "application",
		"lines":     lines,
		"max_bytes": maxBytes,
		"truncated": truncated,
		"content":   redactLog(content),
	}, nil
}

func tailLogFile(path string, lines, maxBytes int) (string, bool, error) {
	file, err := os.Open(path)
	if err != nil {
		return "", false, fmt.Errorf("open application log: %w", err)
	}
	defer file.Close()

	stat, err := file.Stat()
	if err != nil {
		return "", false, fmt.Errorf("stat application log: %w", err)
	}

	window := int64(maxBytes * 4)
	if window < 4096 {
		window = 4096
	}
	if window > 262144 {
		window = 262144
	}

	start := stat.Size() - window
	truncated := start > 0
	if start < 0 {
		start = 0
	}
	if _, err := file.Seek(start, io.SeekStart); err != nil {
		return "", false, fmt.Errorf("seek application log: %w", err)
	}

	data, err := io.ReadAll(io.LimitReader(file, window))
	if err != nil {
		return "", false, fmt.Errorf("read application log: %w", err)
	}

	parts := strings.Split(strings.ReplaceAll(string(data), "\r\n", "\n"), "\n")
	if start > 0 && len(parts) > 0 {
		parts = parts[1:]
	}
	for len(parts) > 0 && parts[len(parts)-1] == "" {
		parts = parts[:len(parts)-1]
	}
	if len(parts) > lines {
		parts = parts[len(parts)-lines:]
		truncated = true
	}

	content := strings.Join(parts, "\n")
	if len(content) > maxBytes {
		content = content[len(content)-maxBytes:]
		if idx := strings.IndexByte(content, '\n'); idx >= 0 {
			content = content[idx+1:]
		}
		truncated = true
	}

	return content, truncated, nil
}

func redactLog(content string) string {
	content = authorizationPattern.ReplaceAllString(content, "$1[REDACTED]")
	return secretPattern.ReplaceAllString(content, "$1[REDACTED]")
}

func boundedInt(args map[string]interface{}, key string, fallback, minValue, maxValue int) (int, error) {
	raw, ok := args[key]
	if !ok || raw == nil {
		return fallback, nil
	}
	value, err := strconv.Atoi(strings.TrimSpace(fmt.Sprint(raw)))
	if err != nil || value < minValue || value > maxValue {
		return 0, fmt.Errorf("%s must be between %d and %d", key, minValue, maxValue)
	}
	return value, nil
}

func networkTarget(args map[string]interface{}) (string, error) {
	target := strings.TrimSpace(fmt.Sprint(args["target"]))
	if target == "" || target == "<nil>" {
		return "", fmt.Errorf("target is required")
	}
	if len(target) > 253 || strings.ContainsAny(target, " /\\@?#\t\r\n") {
		return "", fmt.Errorf("target has invalid characters")
	}
	return target, nil
}

func port(args map[string]interface{}) (int, error) {
	raw := strings.TrimSpace(fmt.Sprint(args["port"]))
	value, err := strconv.Atoi(raw)
	if err != nil || value < 1 || value > 65535 {
		return 0, fmt.Errorf("port must be between 1 and 65535")
	}
	return value, nil
}
