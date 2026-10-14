package machine

// This source is overlaid into internal/machine only by the explicit isolated
// runner. It uses the ordinary Orchestrator, real kernels and real protocol
// sockets; no mocked control-plane or sampled traffic is supplied.
import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"net"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/ANRCM0/TX-Node/internal/config"
	"github.com/ANRCM0/TX-Node/internal/reporting"
	M "github.com/sagernet/sing/common/metadata"
)

func TestNativeCoreAuthorizationLive(t *testing.T) {
	directory := os.Getenv("TXBOARD_NATIVE_AUTHORIZATION_DIR")
	if directory == "" {
		t.Skip("exclusive signed Core fixture not supplied")
	}
	endpoint := os.Getenv("TXBOARD_NATIVE_AUTHORIZATION_URL")
	phase := os.Getenv("TXBOARD_NATIVE_AUTHORIZATION_PHASE")
	if !strings.HasPrefix(endpoint, "https://127.0.0.1:") {
		t.Fatal("trusted loopback HTTPS required")
	}
	var fixture machineCoreLiveFixture
	machineCoreDecode(t, filepath.Join(directory, "fixture.json"), &fixture)
	if fixture.MachineID <= 0 || len(fixture.Nodes) != 2 || fixture.Token == "" || fixture.OriginPort < 1024 {
		t.Fatal("invalid signed authorization fixture")
	}
	var trust struct {
		PublicKey      string `json:"public_key"`
		KeyID          string `json:"key_id"`
		KeyEpoch       string `json:"key_epoch"`
		CoreInstanceID string `json:"core_instance_id"`
	}
	machineCoreDecode(t, filepath.Join(directory, "trust.json"), &trust)
	root := filepath.Join(directory, "kernel")
	if err := os.MkdirAll(root, 0700); err != nil {
		t.Fatal(err)
	}
	cfg := &config.Config{
		InstanceID: "actual-native-signed-authorization",
		Panel:      config.PanelConfig{Provider: "txboard", URL: endpoint},
		Machine:    &config.MachineConfig{MachineID: fixture.MachineID, Token: fixture.Token},
		Kernel:     config.KernelConfig{Type: os.Getenv("TXBOARD_NATIVE_AUTHORIZATION_KERNEL"), ConfigDir: root, LogLevel: "error"},
		Cert:       config.CertConfig{CertMode: "none"},
		Node:       config.NodeConfig{TrackInterval: 1, PushInterval: 5, PullInterval: 60, DeviceReportInterval: 60},
		WS:         config.WSConfig{DiscoveryInterval: 60, HandshakeTimeout: 5, BackoffInitial: 1, BackoffMax: 3},
		Authorization: config.AuthorizationConfig{
			Enabled: true, StateDir: filepath.Join(root, "authorization"), PublicKey: trust.PublicKey,
			KeyID: trust.KeyID, KeyEpoch: trust.KeyEpoch, CoreInstanceID: trust.CoreInstanceID,
			PolicyVersion: "native-node-authorization/v1", RefreshInterval: 5, MaxClockSkew: 1,
		},
	}
	orchestrator, err := NewChecked(cfg)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- orchestrator.Run(ctx) }()
	t.Cleanup(func() {
		cancel()
		select {
		case <-done:
		case <-time.After(10 * time.Second):
			t.Error("authorization cleanup timeout")
		}
	})

	if phase == "offline-restart" {
		// A distinct OS process and the same on-disk state must not open either
		// child while discovery or authorization renewal is unreachable.
		limit := time.NewTimer(3 * time.Second)
		defer limit.Stop()
		ticker := time.NewTicker(20 * time.Millisecond)
		defer ticker.Stop()
		for {
			for _, node := range fixture.Nodes {
				if machineCoreListening(node.Port) {
					t.Fatal("offline restart revived old authorization")
				}
			}
			select {
			case <-ticker.C:
				continue
			case <-limit.C:
			}
			break
		}
		authorizationBarrier(t, directory, "offline-restart", map[string]any{
			"pid": os.Getpid(), "both_listeners_closed": true, "same_persisted_state": true,
		})
		return
	}
	if phase != "capture" && phase != "recovery" {
		t.Fatal("explicit OS process phase required")
	}
	origin := authorizationOrigin(t, fixture.OriginPort)
	destination := M.ParseSocksaddr(origin.Addr().String())
	machineCoreWait(t, done, 12*time.Second, func() bool {
		return machineCoreListening(fixture.Nodes[0].Port) && machineCoreListening(fixture.Nodes[1].Port)
	})
	dial := func(index int) net.Conn {
		stream := machineCoreDial(t, fixture.Nodes[index], destination)
		t.Cleanup(func() { stream.Close() })
		return stream
	}
	first, sibling := dial(0), dial(1)
	machineCoreEcho(t, first, []byte("signed-vless-first-admission"))
	machineCoreEcho(t, sibling, []byte("signed-vmess-first-admission"))
	if phase == "recovery" {
		authorizationBarrier(t, directory, "recovery", map[string]any{
			"pid": os.Getpid(), "both_real_streams_recovered": true, "same_persisted_state": true,
		})
		return
	}
	authorizationBarrier(t, directory, "first-admission", map[string]any{
		"pid": os.Getpid(), "machine_id": fixture.MachineID, "both_real_protocol_streams": true,
	})
	// Runner has now banned the primary user through the registered Admin API,
	// while refusing that child's signed HTTP users responses. Its unsigned WS
	// user hint must not directly change live user credentials or close sockets.
	for index := 0; index < 3; index++ {
		machineCoreEcho(t, first, []byte("unsigned-hint-not-an-authorization"))
		machineCoreEcho(t, sibling, []byte("sibling-during-primary-hint"))
		time.Sleep(300 * time.Millisecond)
	}
	authorizationBarrier(t, directory, "unsigned-hint", map[string]any{
		"actual_primary_stream_survived_unsigned_hint": true, "actual_sibling_stream_survived": true,
	})
	machineCoreClosed(t, first, 12*time.Second)
	machineCoreWait(t, done, 12*time.Second, func() bool {
		return !machineCoreListening(fixture.Nodes[0].Port) && machineCoreListening(fixture.Nodes[1].Port)
	})
	machineCoreEcho(t, sibling, []byte("sibling-remains-after-signed-primary-empty-snapshot"))
	authorizationBarrier(t, directory, "signed-revoked", map[string]any{
		"primary_listener_closed": true, "primary_existing_stream_closed": true, "sibling_real_stream_continues": true,
	})
	machineCoreWait(t, done, 12*time.Second, func() bool { return machineCoreListening(fixture.Nodes[0].Port) })
	first = dial(0)
	machineCoreEcho(t, first, []byte("primary-online-reauthorization"))
	machineCoreEcho(t, sibling, []byte("sibling-online-renewal"))
	authorizationBarrier(t, directory, "offline-start", map[string]any{"both_real_streams_active": true})
	machineCoreEcho(t, first, []byte("primary-offline-before-finite-expiry"))
	machineCoreEcho(t, sibling, []byte("sibling-offline-before-finite-expiry"))
	newBeforeExpiry := dial(0)
	machineCoreEcho(t, newBeforeExpiry, []byte("new-admission-before-finite-expiry"))
	authorizationBarrier(t, directory, "offline-before-expiry", map[string]any{
		"both_existing_streams_continue": true, "new_stream_authorized_before_expiry": true,
	})
	closures := authorizationExpiryClosures(t, fixture, first, sibling, newBeforeExpiry)
	var spools []map[string]any
	checkpointDeadline := time.Now().Add(15 * time.Second)
	for {
		spools = authorizationSpools(t, root)
		complete := true
		for _, node := range fixture.Nodes {
			found := false
			for _, spool := range spools {
				if spool["node_id"] == node.ID {
					found = true
				}
			}
			if !found {
				complete = false
			}
		}
		if complete {
			break
		}
		if time.Now().After(checkpointDeadline) {
			t.Fatal("children did not retain original traffic spools after authorization expiry")
		}
		time.Sleep(20 * time.Millisecond)
	}
	authorizationBarrier(t, directory, "expired", map[string]any{
		"pid": os.Getpid(), "both_existing_streams_closed": true, "both_listeners_closed": true, "spools": spools, "closures": closures,
	})
	t.Fatal("runner must terminate capture process at the recorded expired boundary")
}

func authorizationOrigin(t *testing.T, port int) net.Listener {
	t.Helper()
	listener, err := net.Listen("tcp4", fmt.Sprintf("127.0.0.1:%d", port))
	if err != nil {
		t.Fatal(err)
	}
	var mu sync.Mutex
	var workers sync.WaitGroup
	connections := map[net.Conn]struct{}{}
	t.Cleanup(func() {
		listener.Close()
		mu.Lock()
		for connection := range connections {
			connection.Close()
		}
		mu.Unlock()
		workers.Wait()
	})
	workers.Add(1)
	go func() {
		defer workers.Done()
		for {
			connection, err := listener.Accept()
			if err != nil {
				return
			}
			mu.Lock()
			connections[connection] = struct{}{}
			mu.Unlock()
			workers.Add(1)
			go func() {
				defer workers.Done()
				defer connection.Close()
				defer func() { mu.Lock(); delete(connections, connection); mu.Unlock() }()
				connection.SetDeadline(time.Now().Add(100 * time.Second))
				_, _ = io.Copy(connection, connection)
			}()
		}
	}()
	return listener
}

func authorizationSpools(t *testing.T, root string) []map[string]any {
	t.Helper()
	result := []map[string]any{}
	err := filepath.Walk(root, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			return err
		}
		if info.IsDir() || (!strings.HasSuffix(path, ".pending.json") && !strings.HasSuffix(path, ".pending.json.terminal.json")) {
			return nil
		}
		var batch reporting.Batch
		machineCoreDecode(t, path, &batch)
		digest := sha256.Sum256(mustAuthorizationRead(t, path))
		var appendBatch func(reporting.Batch)
		appendBatch = func(value reporting.Batch) {
			if value.Payload.BatchID != "" && len(value.Payload.Traffic) != 0 {
				nodeID := 0
				relative, _ := filepath.Rel(root, path)
				_, _ = fmt.Sscanf(strings.Split(relative, string(os.PathSeparator))[0], "node-%d", &nodeID)
				result = append(result, map[string]any{"node_id": nodeID, "batch_id": value.Payload.BatchID,
					"traffic": value.Payload.Traffic, "traffic_segments": value.Payload.TrafficSegments,
					"path": relative, "file_sha256": hex.EncodeToString(digest[:])})
			}
			for _, tail := range value.RecoveryTail {
				appendBatch(tail)
			}
		}
		appendBatch(batch)
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	return result
}

func mustAuthorizationRead(t *testing.T, path string) []byte {
	t.Helper()
	body, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	return body
}

func authorizationBarrier(t *testing.T, directory, stage string, value any) {
	t.Helper()
	machineCoreWrite(t, filepath.Join(directory, stage+".ready.json"), value)
	timeout := time.NewTimer(70 * time.Second)
	defer timeout.Stop()
	ticker := time.NewTicker(20 * time.Millisecond)
	defer ticker.Stop()
	for {
		if _, err := os.Stat(filepath.Join(directory, stage+".ack.json")); err == nil {
			return
		}
		select {
		case <-ticker.C:
		case <-timeout.C:
			t.Fatalf("signed authorization runner stage %s timeout", stage)
		}
	}
}

// Observe children concurrently so each timestamp reflects its own IO cutoff,
// rather than the order in which the parent test happens to inspect sockets.
func authorizationExpiryClosures(t *testing.T, fixture machineCoreLiveFixture, first, sibling, additional net.Conn) []map[string]any {
	t.Helper()
	type observed struct {
		nodeID  int
		kind    string
		wall    int64
		failure string
	}
	results := make(chan observed, 5)
	observeStream := func(nodeID int, kind string, stream net.Conn) {
		_ = stream.SetReadDeadline(time.Now().Add(48 * time.Second))
		var data [1]byte
		size, err := stream.Read(data[:])
		failure := ""
		if size != 0 || err == nil {
			failure = "stream did not close"
		}
		if deadline, ok := err.(net.Error); ok && deadline.Timeout() {
			failure = "stream timed out instead of authorization close"
		}
		results <- observed{nodeID: nodeID, kind: kind, wall: time.Now().UnixNano(), failure: failure}
	}
	go observeStream(fixture.Nodes[0].ID, "primary-existing-stream", first)
	go observeStream(fixture.Nodes[1].ID, "sibling-existing-stream", sibling)
	go observeStream(fixture.Nodes[0].ID, "primary-new-before-expiry-stream", additional)
	for _, child := range fixture.Nodes {
		go func(node machineCoreLiveNode) {
			limit := time.NewTimer(48 * time.Second)
			defer limit.Stop()
			ticker := time.NewTicker(20 * time.Millisecond)
			defer ticker.Stop()
			for {
				if !machineCoreListening(node.Port) {
					results <- observed{nodeID: node.ID, kind: "listener", wall: time.Now().UnixNano()}
					return
				}
				select {
				case <-ticker.C:
				case <-limit.C:
					results <- observed{nodeID: node.ID, kind: "listener", wall: time.Now().UnixNano(), failure: "listener did not close"}
					return
				}
			}
		}(child)
	}
	result := []map[string]any{}
	for index := 0; index < 5; index++ {
		closure := <-results
		if closure.failure != "" {
			t.Fatalf("child %d %s: %s", closure.nodeID, closure.kind, closure.failure)
		}
		result = append(result, map[string]any{"node_id": closure.nodeID, "kind": closure.kind, "closed_wall_ns": closure.wall})
	}
	return result
}
