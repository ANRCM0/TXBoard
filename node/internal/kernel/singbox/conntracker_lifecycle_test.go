package singbox

import (
	"context"
	"errors"
	"io"
	"net"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	legacytracker "github.com/ANRCM0/TX-Node/internal/tracker"
	"github.com/sagernet/sing/common/buf"
	"github.com/sagernet/sing/common/bufio"
	singM "github.com/sagernet/sing/common/metadata"
	N "github.com/sagernet/sing/common/network"
	"golang.org/x/time/rate"
)

func lifecyclePacket(t *testing.T, tracker *ConnTracker, uuid string) (N.PacketConn, net.PacketConn, net.PacketConn) {
	t.Helper()
	local, err := net.ListenPacket("udp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	peer, err := net.ListenPacket("udp", "127.0.0.1:0")
	if err != nil {
		_ = local.Close()
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = local.Close(); _ = peer.Close() })
	_ = local.SetDeadline(time.Now().Add(3 * time.Second))
	_ = peer.SetDeadline(time.Now().Add(3 * time.Second))
	wrapped := tracker.RoutedPacketConnection(context.Background(), bufio.NewPacketConn(local), testInboundContext(uuid, "127.0.0.1"), nil, nil)
	t.Cleanup(func() { _ = wrapped.Close() })
	return wrapped, local, peer
}

func lifecycleTCP(t *testing.T, tracker *ConnTracker, uuid string) (net.Conn, net.Conn) {
	t.Helper()
	local, peer := net.Pipe()
	t.Cleanup(func() { _ = local.Close(); _ = peer.Close() })
	_ = local.SetDeadline(time.Now().Add(3 * time.Second))
	_ = peer.SetDeadline(time.Now().Add(3 * time.Second))
	wrapped := tracker.RoutedConnection(context.Background(), local, testInboundContext(uuid, "127.0.0.1"), nil, nil)
	t.Cleanup(func() { _ = wrapped.Close() })
	return wrapped, peer
}

func assertLifecycleCounts(t *testing.T, tracker *ConnTracker, count int) {
	t.Helper()
	_, alive, got := tracker.GetUserTraffic()
	if got != count || tracker.ActiveCount() != count {
		t.Fatalf("active = %d/%d, want %d", got, tracker.ActiveCount(), count)
	}
	if count == 0 && len(alive) != 0 {
		t.Fatalf("closed sessions retained alive IPs: %v", alive)
	}
	tracker.usersMu.RLock()
	registered := len(tracker.connMap)
	tracker.usersMu.RUnlock()
	if registered != count {
		t.Fatalf("registered = %d, want %d", registered, count)
	}
}

func TestCloseUserConnectionsTerminatesRealTCPAndUDPAndPreservesLegacyBytes(t *testing.T) {
	tracker := NewConnTracker(0)
	tracker.SetUserMap(map[string]int{"user-one": 1, "user-two": 2})
	tcp, tcpPeer := lifecycleTCP(t, tracker, "user-one")
	other, otherPeer := lifecycleTCP(t, tracker, "user-two")
	udp, udpLocal, udpPeer := lifecyclePacket(t, tracker, "user-one")

	tcpWrite := make(chan error, 1)
	go func() { _, err := tcpPeer.Write([]byte("upload")); tcpWrite <- err }()
	data := make([]byte, 16)
	if n, err := tcp.Read(data); err != nil || string(data[:n]) != "upload" {
		t.Fatalf("TCP read = %q, %v", data[:n], err)
	}
	if err := <-tcpWrite; err != nil {
		t.Fatal(err)
	}
	go func() { _, err := tcp.Write([]byte("down")); tcpWrite <- err }()
	if n, err := tcpPeer.Read(data); err != nil || string(data[:n]) != "down" {
		t.Fatalf("TCP peer read = %q, %v", data[:n], err)
	}
	if err := <-tcpWrite; err != nil {
		t.Fatal(err)
	}

	if _, err := udpPeer.WriteTo([]byte("packet"), udpLocal.LocalAddr()); err != nil {
		t.Fatal(err)
	}
	packet := buf.NewPacket()
	defer packet.Release()
	if _, err := udp.ReadPacket(packet); err != nil || string(packet.Bytes()) != "packet" {
		t.Fatalf("UDP read = %q, %v", packet.Bytes(), err)
	}
	if err := udp.WritePacket(buf.As([]byte("reply")), singM.SocksaddrFromNet(udpPeer.LocalAddr())); err != nil {
		t.Fatal(err)
	}
	if n, _, err := udpPeer.ReadFrom(data); err != nil || string(data[:n]) != "reply" {
		t.Fatalf("UDP peer read = %q, %v", data[:n], err)
	}
	assertLifecycleCounts(t, tracker, 3)

	blockedTCP := make(chan error, 1)
	blockedUDP := make(chan error, 1)
	go func() { _, err := tcp.Read(make([]byte, 1)); blockedTCP <- err }()
	go func() { b := buf.NewPacket(); defer b.Release(); _, err := udp.ReadPacket(b); blockedUDP <- err }()
	kernel := &SingBox{connTracker: tracker}
	if err := kernel.CloseUserConnections(context.Background(), "user-one"); err != nil {
		t.Fatal(err)
	}
	for name, result := range map[string]<-chan error{"TCP": blockedTCP, "UDP": blockedUDP} {
		select {
		case err := <-result:
			if err == nil {
				t.Fatalf("%s I/O remained open", name)
			}
			if timeout, ok := err.(net.Error); ok && timeout.Timeout() {
				t.Fatalf("%s waited for deadline instead of closing: %v", name, err)
			}
		case <-time.After(time.Second):
			t.Fatalf("%s close did not cancel blocked I/O", name)
		}
	}
	assertLifecycleCounts(t, tracker, 1)
	if n, err := tcpPeer.Read(data); n != 0 || err == nil {
		t.Fatalf("closed TCP peer = %d, %v", n, err)
	}
	if err := udp.WritePacket(buf.As([]byte("x")), singM.SocksaddrFromNet(udpPeer.LocalAddr())); !errors.Is(err, net.ErrClosed) {
		t.Fatalf("closed UDP write = %v", err)
	}
	go func() { _, err := otherPeer.Write([]byte("ok")); tcpWrite <- err }()
	if n, err := other.Read(data); err != nil || string(data[:n]) != "ok" {
		t.Fatalf("unrelated TCP was interrupted: %q, %v", data[:n], err)
	}
	if err := <-tcpWrite; err != nil {
		t.Fatal(err)
	}
	if tracker.CloseByUUID("user-one") != 0 {
		t.Fatal("already closed registry entries remained")
	}
	_ = other.Close()
	assertLifecycleCounts(t, tracker, 0)

	traffic, alive, count := tracker.GetUserTraffic()
	if traffic[1] != [2]int64{12, 9} || traffic[2] != [2]int64{2, 0} {
		t.Fatalf("final cumulative bytes = %v", traffic)
	}
	tracker.SetUserMap(map[string]int{})
	retained, _, _ := tracker.GetUserTraffic()
	if retained[1] != traffic[1] {
		t.Fatal("removal lost final cumulative counters")
	}
	legacy := legacytracker.New()
	legacy.Process(retained, alive, count)
	if flushed := legacy.FlushTraffic(); flushed[1] != traffic[1] {
		t.Fatalf("v1 report lost removed user's bytes: %v", flushed)
	}
	legacy.Process(retained, nil, 0)
	if legacy.HasTraffic() {
		t.Fatal("unchanged retained totals were billed twice")
	}
}

func TestUserRemovalClosesBothTransportsAndRejectsUnknownOrRemovedIdentity(t *testing.T) {
	tracker := NewConnTracker(0)
	input := map[string]int{"removed": 1, "kept": 2}
	tracker.SetUserMap(input)
	delete(input, "kept") // Caller cannot mutate the live admission map.
	tcp, _ := lifecycleTCP(t, tracker, "removed")
	udp, _, removedPeer := lifecyclePacket(t, tracker, "removed")
	kept, _ := lifecycleTCP(t, tracker, "kept")
	tracker.SetUserMap(map[string]int{"kept": 2})
	assertLifecycleCounts(t, tracker, 1)
	if _, err := tcp.Read(make([]byte, 1)); err == nil {
		t.Fatal("removed TCP remained open")
	}
	if err := udp.WritePacket(buf.As([]byte("x")), singM.SocksaddrFromNet(removedPeer.LocalAddr())); err == nil {
		t.Fatal("removed UDP remained open")
	}
	for _, uuid := range []string{"removed", "unknown", ""} {
		closed, peer := lifecycleTCP(t, tracker, uuid)
		if _, ok := closed.(*trackedConn); ok {
			t.Fatalf("%q acquired a tracked TCP session", uuid)
		}
		if n, err := peer.Read(make([]byte, 1)); n != 0 || err == nil {
			t.Fatalf("rejected TCP %q stayed usable", uuid)
		}
		packet, _, packetPeer := lifecyclePacket(t, tracker, uuid)
		if _, ok := packet.(*trackedPacketConn); ok {
			t.Fatalf("%q acquired a tracked UDP session", uuid)
		}
		if err := packet.WritePacket(buf.As([]byte("x")), singM.SocksaddrFromNet(packetPeer.LocalAddr())); !errors.Is(err, net.ErrClosed) {
			t.Fatalf("rejected UDP %q = %v", uuid, err)
		}
	}
	assertLifecycleCounts(t, tracker, 1)
	_ = kept.Close()
	assertLifecycleCounts(t, tracker, 0)
}

func TestAdmissionRechecksRemovalAfterCallbacksForTCPAndUDP(t *testing.T) {
	for _, protocol := range []string{"tcp", "udp"} {
		t.Run(protocol, func(t *testing.T) {
			tracker := NewConnTracker(0)
			tracker.SetUserMap(map[string]int{"removed": 1})
			entered := make(chan struct{})
			release := make(chan struct{})
			tracker.SetSpeedLimitFunc(func(string) *rate.Limiter { close(entered); <-release; return nil })
			finished := make(chan io.Closer, 1)
			if protocol == "tcp" {
				local, peer := net.Pipe()
				defer local.Close()
				defer peer.Close()
				go func() {
					finished <- tracker.RoutedConnection(context.Background(), local, testInboundContext("removed", "127.0.0.1"), nil, nil)
				}()
			} else {
				local, err := net.ListenPacket("udp", "127.0.0.1:0")
				if err != nil {
					t.Fatal(err)
				}
				defer local.Close()
				go func() {
					finished <- tracker.RoutedPacketConnection(context.Background(), bufio.NewPacketConn(local), testInboundContext("removed", "127.0.0.1"), nil, nil)
				}()
			}
			<-entered
			tracker.SetUserMap(map[string]int{})
			close(release)
			select {
			case connection := <-finished:
				defer connection.Close()
				switch connection.(type) {
				case *trackedConn, *trackedPacketConn:
					t.Fatal("removal race registered a stale identity")
				}
			case <-time.After(time.Second):
				t.Fatal("admission deadlocked")
			}
			assertLifecycleCounts(t, tracker, 0)
		})
	}
}

type countedCloseConn struct {
	net.Conn
	closes atomic.Int64
}

func (c *countedCloseConn) Close() error { c.closes.Add(1); return c.Conn.Close() }

func TestConcurrentClosePathsCleanUpExactlyOnceWithoutResettingCounters(t *testing.T) {
	tracker := NewConnTracker(0)
	tracker.SetUserMap(map[string]int{"user": 1})
	local, peer := net.Pipe()
	defer peer.Close()
	base := &countedCloseConn{Conn: local}
	connection := tracker.RoutedConnection(context.Background(), base, testInboundContext("user", "127.0.0.1"), nil, nil).(*trackedConn)
	_, counters := connection.UnwrapReader()
	counters[0](17)
	_, counters = connection.UnwrapWriter()
	counters[0](23)
	var workers sync.WaitGroup
	for i := 0; i < 32; i++ {
		workers.Add(1)
		go func(i int) {
			defer workers.Done()
			switch i % 4 {
			case 0:
				_ = connection.Close()
			case 1:
				tracker.CloseByUUID("user")
			case 2:
				tracker.CloseByID(connection.connID)
			case 3:
				tracker.SetUserMap(map[string]int{})
			}
		}(i)
	}
	workers.Wait()
	assertLifecycleCounts(t, tracker, 0)
	if got := base.closes.Load(); got != 1 {
		t.Fatalf("underlying close count = %d, want 1", got)
	}
	traffic, _, _ := tracker.GetUserTraffic()
	if traffic[1] != [2]int64{17, 23} {
		t.Fatalf("zero-copy totals changed: %v", traffic)
	}
}

func TestForceCloseCancelsRateLimitedZeroCopyWait(t *testing.T) {
	tracker := NewConnTracker(0)
	tracker.SetUserMap(map[string]int{"limited": 1})
	limit := rate.NewLimiter(rate.Limit(0.001), 1)
	tracker.SetSpeedLimitFunc(func(string) *rate.Limiter { return limit })
	connection, _ := lifecycleTCP(t, tracker, "limited")
	tracked := connection.(*trackedConn)
	_, counters := tracked.UnwrapReader()
	counters[0](1) // Consume burst before the second callback waits.
	finished := make(chan struct{})
	go func() { counters[0](1); close(finished) }()
	deadline := time.Now().Add(time.Second)
	for limit.Tokens() >= 0 && time.Now().Before(deadline) {
		time.Sleep(time.Millisecond)
	}
	if limit.Tokens() >= 0 {
		t.Fatal("zero-copy callback never entered its rate wait")
	}
	tracker.CloseByUUID("limited")
	select {
	case <-finished:
	case <-time.After(time.Second):
		t.Fatal("force-close did not cancel rate wait")
	}
	assertLifecycleCounts(t, tracker, 0)
	traffic, _, _ := tracker.GetUserTraffic()
	if traffic[1][0] != 2 {
		t.Fatalf("observed bytes disappeared while canceling rate wait: %v", traffic)
	}
}

type countedPacketClose struct {
	N.PacketConn
	closes atomic.Int64
}

func (c *countedPacketClose) Close() error { c.closes.Add(1); return c.PacketConn.Close() }

func TestConcurrentPacketCloseAndReAdmissionKeepRegistryAndCounters(t *testing.T) {
	tracker := NewConnTracker(0)
	tracker.SetUserMap(map[string]int{"packet": 1})
	local, err := net.ListenPacket("udp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer local.Close()
	base := &countedPacketClose{PacketConn: bufio.NewPacketConn(local)}
	packet := tracker.RoutedPacketConnection(context.Background(), base, testInboundContext("packet", "127.0.0.1"), nil, nil).(*trackedPacketConn)
	// Exercise both existing packet zero-copy hooks without changing their
	// legacy byte-direction convention in this connection-management slice.
	_, counters := packet.UnwrapPacketWriter()
	counters[0](13)
	_, counters = packet.UnwrapPacketReader()
	counters[0](19)
	var workers sync.WaitGroup
	for i := 0; i < 24; i++ {
		workers.Add(1)
		go func(i int) {
			defer workers.Done()
			switch i % 3 {
			case 0:
				_ = packet.Close()
			case 1:
				tracker.CloseByUUID("packet")
			case 2:
				tracker.CloseByID(packet.connID)
			}
		}(i)
	}
	workers.Wait()
	assertLifecycleCounts(t, tracker, 0)
	if base.closes.Load() != 1 {
		t.Fatalf("UDP underlying close count = %d", base.closes.Load())
	}
	traffic, _, _ := tracker.GetUserTraffic()
	if traffic[1] != [2]int64{13, 19} {
		t.Fatalf("packet cumulative counters changed: %v", traffic)
	}
	// CloseUserConnections closes a snapshot of established sessions, rather
	// than revoking an otherwise still-authorized identity.
	newPacket, _, _ := lifecyclePacket(t, tracker, "packet")
	if _, ok := newPacket.(*trackedPacketConn); !ok {
		t.Fatal("explicit close unexpectedly revoked admission")
	}
	assertLifecycleCounts(t, tracker, 1)
	tracker.SetUserMap(map[string]int{})
	assertLifecycleCounts(t, tracker, 0)
}

func TestCloseByUUIDUsesRegisteredIdentityWhenRuntimeUserIsShared(t *testing.T) {
	tracker := NewConnTracker(0)
	tracker.SetUserMap(map[string]int{"first": 1, "second": 1})
	_, _ = lifecycleTCP(t, tracker, "first")
	other, _ := lifecycleTCP(t, tracker, "second")
	if got := tracker.CloseByUUID("first"); got != 1 {
		t.Fatalf("closed = %d, want 1", got)
	}
	assertLifecycleCounts(t, tracker, 1)
	tracker.SetUserMap(map[string]int{"second": 2})
	assertLifecycleCounts(t, tracker, 0)
	if _, err := other.Read(make([]byte, 1)); err == nil {
		t.Fatal("reassigned identity's old connection remained open")
	}
}
