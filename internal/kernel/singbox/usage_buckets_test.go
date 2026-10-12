package singbox

import (
	"bytes"
	"context"
	"errors"
	"io"
	"math"
	"net"
	"reflect"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/ANRCM0/TX-Node/internal/usagebucket"
	"github.com/sagernet/sing/common/buf"
	"github.com/sagernet/sing/common/bufio"
	singM "github.com/sagernet/sing/common/metadata"
	N "github.com/sagernet/sing/common/network"
	"golang.org/x/time/rate"
)

func eventTracker(t *testing.T, options usagebucket.Options) *ConnTracker {
	t.Helper()
	options.Enabled = true
	if options.Window == 0 {
		options.Window = time.Hour
	}
	tracker, err := NewConnTrackerWithEventBuckets(options)
	if err != nil {
		t.Fatal(err)
	}
	tracker.SetUserMap(map[string]int{"user": 7})
	return tracker
}
func countEvents(tracker *ConnTracker) []usagebucket.Event {
	snapshot := tracker.EventBuckets().Snapshot()
	var result []usagebucket.Event
	for _, bucket := range append(snapshot.Sealed, snapshot.Open) {
		for _, e := range bucket.Events {
			if e.Kind == "accounting_completion" && e.RawCount != 0 {
				result = append(result, e)
			}
		}
	}
	return result
}
func assertV1(t *testing.T, tracker *ConnTracker, expected [2]int64) {
	t.Helper()
	traffic, _, _ := tracker.GetUserTraffic()
	if traffic[7] != expected {
		t.Fatalf("legacy traffic %v, want %v", traffic[7], expected)
	}
}

type allocationFreeConn struct{ net.Conn }

func (c *allocationFreeConn) Read(p []byte) (int, error)  { p[0] = 1; return 1, nil }
func (c *allocationFreeConn) Write(p []byte) (int, error) { return len(p), nil }

func TestEventBucketsDefaultPathHasNoMeterClockOrAllocations(t *testing.T) {
	tracker, err := NewConnTrackerWithEventBuckets(usagebucket.Options{Clock: func() usagebucket.Stamp { panic("disabled clock") }})
	if err != nil {
		t.Fatal(err)
	}
	tracker.SetUserMap(map[string]int{"user": 7})
	local, peer := net.Pipe()
	defer local.Close()
	defer peer.Close()
	connection := tracker.RoutedConnection(context.Background(), &allocationFreeConn{local}, testInboundContext("user", "127.0.0.1"), nil, nil).(*trackedConn)
	if tracker.EventBuckets() != nil || connection.bucketSession != nil {
		t.Fatal("default observer allocated")
	}
	_, callbacks := connection.UnwrapReader()
	data := make([]byte, 1)
	if allocations := testing.AllocsPerRun(100, func() { _, _ = connection.Read(data); _, _ = connection.Write(data); callbacks[0](1) }); allocations != 0 {
		t.Fatalf("default accounting allocations %v", allocations)
	}
}

func TestEventBucketsDirectTCPAndWriteBufferCountOnce(t *testing.T) {
	tracker := eventTracker(t, usagebucket.Options{})
	connection, peer := lifecycleTCP(t, tracker, "user")
	finished := make(chan error, 1)
	go func() { _, err := peer.Write([]byte("upload")); finished <- err }()
	data := make([]byte, 6)
	if _, err := io.ReadFull(connection, data); err != nil {
		t.Fatal(err)
	}
	if err := <-finished; err != nil {
		t.Fatal(err)
	}
	go func() { _, err := io.ReadFull(peer, make([]byte, 8)); finished <- err }()
	if err := bufio.NewExtendedWriter(connection).WriteBuffer(buf.As([]byte("download"))); err != nil {
		t.Fatal(err)
	}
	if err := <-finished; err != nil {
		t.Fatal(err)
	}
	assertV1(t, tracker, [2]int64{6, 8})
	events := countEvents(tracker)
	if len(events) != 2 || events[0].Path != usagebucket.TCPRead || events[1].Path != usagebucket.TCPWrite {
		t.Fatalf("WriteBuffer counted recursively: %+v", events)
	}
	for _, event := range events {
		if !event.HasIOStart || event.IntervalUnknown || event.DirectionMismatch {
			t.Fatalf("direct range: %+v", event)
		}
	}
}

func TestEventBucketsPinnedSingCopyOwnsTCPCountCallbacks(t *testing.T) {
	for _, direction := range []string{"read", "write"} {
		t.Run(direction, func(t *testing.T) {
			tracker := eventTracker(t, usagebucket.Options{})
			connection, peer := lifecycleTCP(t, tracker, "user")
			const payload = "copied-by-sing"
			if direction == "read" {
				finished := make(chan error, 1)
				go func() { _, err := peer.Write([]byte(payload)); _ = peer.Close(); finished <- err }()
				var destination bytes.Buffer
				n, err := bufio.Copy(&destination, connection)
				if err != nil || n != int64(len(payload)) || destination.String() != payload {
					t.Fatalf("sing read copy %d %v %q", n, err, destination.String())
				}
				if err := <-finished; err != nil {
					t.Fatal(err)
				}
				assertV1(t, tracker, [2]int64{int64(len(payload)), 0})
			} else {
				finished := make(chan error, 1)
				go func() { _, err := io.ReadFull(peer, make([]byte, len(payload))); finished <- err }()
				n, err := bufio.Copy(connection, strings.NewReader(payload))
				if err != nil || n != int64(len(payload)) {
					t.Fatalf("sing write copy %d %v", n, err)
				}
				if err := <-finished; err != nil {
					t.Fatal(err)
				}
				assertV1(t, tracker, [2]int64{0, int64(len(payload))})
			}
			events := countEvents(tracker)
			var total int64
			for _, event := range events {
				total += event.RawCount
				if event.HasIOStart || !event.IntervalUnknown || event.Path != map[string]usagebucket.Path{"read": usagebucket.TCPReadCallback, "write": usagebucket.TCPWriteCallback}[direction] {
					t.Fatalf("copy falsely used direct timing: %+v", event)
				}
			}
			if total != int64(len(payload)) || len(events) != 1 {
				t.Fatalf("sing copied bytes counted more than once: %+v", events)
			}
		})
	}
}

type errorAfterIO struct{ net.Conn }

func (c *errorAfterIO) Read(p []byte) (int, error) {
	n, err := c.Conn.Read(p)
	if n > 0 {
		return n, io.EOF
	}
	return n, err
}
func (c *errorAfterIO) Write(p []byte) (int, error) {
	if len(p) > 2 {
		p = p[:2]
	}
	n, err := c.Conn.Write(p)
	if n > 0 {
		return n, io.ErrClosedPipe
	}
	return n, err
}

func TestEventBucketsPartialTCPWithErrorPreservesActualBytes(t *testing.T) {
	tracker := eventTracker(t, usagebucket.Options{})
	local, peer := net.Pipe()
	defer peer.Close()
	connection := tracker.RoutedConnection(context.Background(), &errorAfterIO{local}, testInboundContext("user", "127.0.0.1"), nil, nil)
	defer connection.Close()
	finished := make(chan error, 1)
	go func() { _, err := peer.Write([]byte("abc")); finished <- err }()
	if n, err := connection.Read(make([]byte, 3)); n != 3 || !errors.Is(err, io.EOF) {
		t.Fatalf("partial read %d %v", n, err)
	}
	if err := <-finished; err != nil {
		t.Fatal(err)
	}
	go func() { _, err := io.ReadFull(peer, make([]byte, 2)); finished <- err }()
	if n, err := connection.Write([]byte("long")); n != 2 || !errors.Is(err, io.ErrClosedPipe) {
		t.Fatalf("partial write %d %v", n, err)
	}
	if err := <-finished; err != nil {
		t.Fatal(err)
	}
	assertV1(t, tracker, [2]int64{3, 2})
	events := countEvents(tracker)
	if len(events) != 2 || !events[0].IOError || !events[1].IOError || events[1].RawCount != 2 {
		t.Fatalf("attempted bytes were charged: %+v", events)
	}
}

func TestEventBucketsDirectUDPKeepsLegacyDirections(t *testing.T) {
	tracker := eventTracker(t, usagebucket.Options{})
	connection, local, peer := lifecyclePacket(t, tracker, "user")
	if _, err := peer.WriteTo([]byte("up"), local.LocalAddr()); err != nil {
		t.Fatal(err)
	}
	packet := buf.NewPacket()
	_, err := connection.ReadPacket(packet)
	packet.Release()
	if err != nil {
		t.Fatal(err)
	}
	if err := connection.WritePacket(buf.As([]byte("down")), singM.SocksaddrFromNet(peer.LocalAddr())); err != nil {
		t.Fatal(err)
	}
	if n, _, err := peer.ReadFrom(make([]byte, 16)); n != 4 || err != nil {
		t.Fatalf("UDP %d %v", n, err)
	}
	assertV1(t, tracker, [2]int64{2, 4})
	events := countEvents(tracker)
	if len(events) != 2 || events[0].Path != usagebucket.UDPRead || events[1].Path != usagebucket.UDPWrite || events[0].DirectionMismatch || events[1].DirectionMismatch || !events[0].HasIOStart {
		t.Fatalf("direct UDP ownership: %+v", events)
	}
}

type onePacketConn struct {
	N.PacketConn
	remaining   int
	destination singM.Socksaddr
}

func (c *onePacketConn) ReadPacket(buffer *buf.Buffer) (singM.Socksaddr, error) {
	if c.remaining == 0 {
		return singM.Socksaddr{}, io.EOF
	}
	c.remaining--
	return c.PacketConn.ReadPacket(buffer)
}
func (c *onePacketConn) WritePacket(buffer *buf.Buffer, destination singM.Socksaddr) error {
	if c.destination.IsValid() {
		destination = c.destination
	}
	return c.PacketConn.WritePacket(buffer, destination)
}

// This hides replacement upstream traversal but preserves the pinned counter
// interface. It tests the supported callback contract, not default writer coverage.
type packetCounterBoundary struct{ N.PacketWriteCounter }

func (c packetCounterBoundary) WriterReplaceable() bool { return false }

func TestEventBucketsPinnedSingPacketCopyDirectionAndWriterCoverage(t *testing.T) {
	for _, mode := range []string{"reader", "default_writer_bypass", "counter_writer"} {
		t.Run(mode, func(t *testing.T) {
			tracker := eventTracker(t, usagebucket.Options{})
			open := func() net.PacketConn {
				socket, err := net.ListenPacket("udp", "127.0.0.1:0")
				if err != nil {
					t.Fatal(err)
				}
				t.Cleanup(func() { _ = socket.Close() })
				_ = socket.SetDeadline(time.Now().Add(3 * time.Second))
				return socket
			}
			source, peer, writer, receiver := open(), open(), open(), open()
			sourceConn := &onePacketConn{PacketConn: bufio.NewPacketConn(source), remaining: 1}
			writerConn := &onePacketConn{PacketConn: bufio.NewPacketConn(writer), destination: singM.SocksaddrFromNet(receiver.LocalAddr())}
			var reader N.PacketReader = sourceConn
			var destination N.PacketWriter = writerConn
			if mode == "reader" {
				tracked := tracker.RoutedPacketConnection(context.Background(), sourceConn, testInboundContext("user", "127.0.0.1"), nil, nil)
				defer tracked.Close()
				reader = tracked
			} else {
				tracked := tracker.RoutedPacketConnection(context.Background(), writerConn, testInboundContext("user", "127.0.0.1"), nil, nil)
				defer tracked.Close()
				destination = tracked
				if mode == "counter_writer" {
					destination = packetCounterBoundary{tracked.(N.PacketWriteCounter)}
				}
			}
			const payload = "packet-copy"
			if _, err := peer.WriteTo([]byte(payload), source.LocalAddr()); err != nil {
				t.Fatal(err)
			}
			n, err := bufio.CopyPacket(destination, reader)
			if n != int64(len(payload)) || !errors.Is(err, io.EOF) {
				t.Fatalf("sing packet copy %d %v", n, err)
			}
			data := make([]byte, 32)
			if n, _, err := receiver.ReadFrom(data); err != nil || string(data[:n]) != payload {
				t.Fatalf("actual copied packet %q %v", data[:n], err)
			}
			events := countEvents(tracker)
			if mode == "default_writer_bypass" {
				assertV1(t, tracker, [2]int64{})
				if len(events) != 0 {
					t.Fatal("observer invented a skipped legacy count site")
				}
				return
			}
			if len(events) != 1 || !events[0].DirectionMismatch || !events[0].IntervalUnknown || events[0].HasIOStart {
				t.Fatalf("packet callback ownership: %+v", events)
			}
			if mode == "reader" {
				assertV1(t, tracker, [2]int64{0, int64(len(payload))})
				if events[0].Path != usagebucket.UDPReadCallback || events[0].PhysicalDirection != usagebucket.Upload || events[0].LegacyDirection != usagebucket.Download {
					t.Fatal("reader direction rewritten")
				}
			} else {
				assertV1(t, tracker, [2]int64{int64(len(payload)), 0})
				if events[0].Path != usagebucket.UDPWriteCallback || events[0].PhysicalDirection != usagebucket.Download || events[0].LegacyDirection != usagebucket.Upload {
					t.Fatal("writer direction rewritten")
				}
			}
		})
	}
}

type delayedReadCompletion struct {
	net.Conn
	observed chan struct{}
	release  chan struct{}
}

func (c *delayedReadCompletion) Read(p []byte) (int, error) {
	n, err := c.Conn.Read(p)
	if n > 0 {
		close(c.observed)
		<-c.release
	}
	return n, err
}

func TestEventBucketsReadReturningAfterCloseAndReassignmentKeepsOriginalUser(t *testing.T) {
	tracker := eventTracker(t, usagebucket.Options{})
	local, peer := net.Pipe()
	defer peer.Close()
	base := &delayedReadCompletion{local, make(chan struct{}), make(chan struct{})}
	connection := tracker.RoutedConnection(context.Background(), base, testInboundContext("user", "127.0.0.1"), nil, nil).(*trackedConn)
	finished := make(chan error, 1)
	go func() { _, err := connection.Read(make([]byte, 4)); finished <- err }()
	if _, err := peer.Write([]byte("late")); err != nil {
		t.Fatal(err)
	}
	<-base.observed
	tracker.SetUserMap(map[string]int{"user": 8})
	if !tracker.EventBuckets().Cut(usagebucket.LifecycleFence, true) {
		t.Fatal("cut")
	}
	sealed := tracker.EventBuckets().Snapshot().Sealed[0]
	close(base.release)
	if err := <-finished; err != nil {
		t.Fatal(err)
	}
	events := countEvents(tracker)
	if len(events) != 1 {
		t.Fatal("late read lost")
	}
	event := events[0]
	if event.UserID != 7 || !event.LateAfterClose || !event.HasIOStart || !event.CrossedFence || event.BindingGeneration != 1 || event.ObservationGeneration != 2 {
		t.Fatalf("late read rebound: %+v", event)
	}
	if !reflect.DeepEqual(sealed, tracker.EventBuckets().Snapshot().Sealed[0]) {
		t.Fatal("late read changed sealed bucket")
	}
	traffic, _, _ := tracker.GetUserTraffic()
	if traffic[7] != [2]int64{4, 0} || traffic[8] != [2]int64{} {
		t.Fatal("late read changed v1 identity")
	}
}

func TestEventBucketsHeldZeroCopyCallbackAfterCloseIsUnknownLateEvidence(t *testing.T) {
	tracker := eventTracker(t, usagebucket.Options{})
	connection, peer := lifecycleTCP(t, tracker, "user")
	raw, callbacks := N.UnwrapCountReader(connection, nil)
	finished := make(chan error, 1)
	go func() { _, err := peer.Write([]byte("held")); finished <- err }()
	if _, err := io.ReadFull(raw, make([]byte, 4)); err != nil {
		t.Fatal(err)
	}
	if err := <-finished; err != nil {
		t.Fatal(err)
	}
	_ = connection.Close()
	if !tracker.EventBuckets().Cut(usagebucket.LifecycleFence, true) {
		t.Fatal("cut")
	}
	if tracker.ActiveCount() != 0 {
		t.Fatal("close did not clean registry")
	}
	callbacks[0](4)
	events := countEvents(tracker)
	if len(events) != 1 || !events[0].LateAfterClose || !events[0].IntervalUnknown || events[0].HasIOStart || events[0].BindingGeneration != 1 {
		t.Fatalf("late callback: %+v", events)
	}
	assertV1(t, tracker, [2]int64{4, 0})
}

func TestEventBucketsUDPConcurrentCloseAndRetainedCallbackPreserveBinding(t *testing.T) {
	tracker := eventTracker(t, usagebucket.Options{})
	connection, local, peer := lifecyclePacket(t, tracker, "user")
	raw, callbacks := N.UnwrapCountPacketReader(connection, nil)
	if _, err := peer.WriteTo([]byte("held-packet"), local.LocalAddr()); err != nil {
		t.Fatal(err)
	}
	packet := buf.NewPacket()
	if _, err := raw.ReadPacket(packet); err != nil {
		packet.Release()
		t.Fatal(err)
	}
	n := int64(packet.Len())
	packet.Release()
	tracked := connection.(*trackedPacketConn)
	var workers sync.WaitGroup
	for i := 0; i < 16; i++ {
		workers.Add(1)
		go func(i int) {
			defer workers.Done()
			switch i % 3 {
			case 0:
				_ = connection.Close()
			case 1:
				tracker.CloseByID(tracked.connID)
			case 2:
				tracker.SetUserMap(map[string]int{"user": 8})
			}
		}(i)
	}
	workers.Wait()
	if !tracker.EventBuckets().Cut(usagebucket.LifecycleFence, true) {
		t.Fatal("cut")
	}
	sealed := tracker.EventBuckets().Snapshot().Sealed[0]
	callbacks[0](n)
	events := countEvents(tracker)
	if len(events) != 1 || events[0].UserID != 7 || !events[0].LateAfterClose || !events[0].IntervalUnknown || !events[0].DirectionMismatch || events[0].Path != usagebucket.UDPReadCallback {
		t.Fatalf("late UDP callback rebound: %+v", events)
	}
	if !reflect.DeepEqual(sealed, tracker.EventBuckets().Snapshot().Sealed[0]) {
		t.Fatal("late UDP altered sealed evidence")
	}
	traffic, _, count := tracker.GetUserTraffic()
	if count != 0 || traffic[7] != [2]int64{0, n} || traffic[8] != [2]int64{} {
		t.Fatalf("legacy late UDP semantics changed: %v", traffic)
	}
}

func TestEventBucketsUDPErrorsRetainUnknownCountWithoutAttemptedBytes(t *testing.T) {
	tracker := eventTracker(t, usagebucket.Options{})
	connection, _, peer := lifecyclePacket(t, tracker, "user")
	_ = connection.Close()
	packet := buf.NewPacket()
	_, readErr := connection.ReadPacket(packet)
	packet.Release()
	if readErr == nil {
		t.Fatal("closed UDP read succeeded")
	}
	if err := connection.WritePacket(buf.As([]byte("not-delivered")), singM.SocksaddrFromNet(peer.LocalAddr())); err == nil {
		t.Fatal("closed UDP write succeeded")
	}
	snapshot := tracker.EventBuckets().Snapshot()
	if len(snapshot.Open.Events) != 3 {
		t.Fatalf("failed UDP evidence absent: %+v", snapshot.Open.Events)
	}
	for _, event := range snapshot.Open.Events[1:] {
		if event.CountKnown || event.RawCount != 0 || !event.IOError || !event.HasIOStart || !event.LateAfterClose || !event.StartedAfterClose {
			t.Fatalf("failed UDP forged delivery: %+v", event)
		}
	}
	assertV1(t, tracker, [2]int64{})
}

func TestEventBucketsRateWaitDoesNotHoldBucketMutexAndClosePreservesBytes(t *testing.T) {
	tracker := eventTracker(t, usagebucket.Options{})
	limit := rate.NewLimiter(0.001, 1)
	tracker.SetSpeedLimitFunc(func(string) *rate.Limiter { return limit })
	connection, _ := lifecycleTCP(t, tracker, "user")
	_, callbacks := connection.(*trackedConn).UnwrapReader()
	callbacks[0](1)
	finished := make(chan struct{})
	go func() { callbacks[0](1); close(finished) }()
	deadline := time.Now().Add(time.Second)
	for limit.Tokens() >= 0 && time.Now().Before(deadline) {
		time.Sleep(time.Millisecond)
	}
	if limit.Tokens() >= 0 {
		t.Fatal("expected rate wait")
	}
	cut := make(chan bool, 1)
	go func() { cut <- tracker.EventBuckets().Cut(usagebucket.ManualFence, false) }()
	select {
	case ok := <-cut:
		if !ok {
			t.Fatal("cut")
		}
	case <-time.After(time.Second):
		t.Fatal("rate wait held meter mutex")
	}
	tracker.CloseByUUID("user")
	select {
	case <-finished:
	case <-time.After(time.Second):
		t.Fatal("close did not cancel wait")
	}
	assertV1(t, tracker, [2]int64{2, 0})
	if len(countEvents(tracker)) != 2 {
		t.Fatal("rate-wait bytes vanished")
	}
}

func TestEventBucketsCapacityAndOverflowNeverGateLegacyCounters(t *testing.T) {
	for _, mode := range []string{"capacity", "overflow", "negative"} {
		t.Run(mode, func(t *testing.T) {
			options := usagebucket.Options{MaxEvents: 8, MaxSealed: 1}
			if mode == "capacity" {
				options.MaxEvents = 1
			}
			tracker := eventTracker(t, options)
			connection, _ := lifecycleTCP(t, tracker, "user")
			_, callbacks := connection.(*trackedConn).UnwrapReader()
			if mode == "overflow" {
				callbacks[0](math.MaxInt64)
				callbacks[0](1)
			} else if mode == "negative" {
				callbacks[0](-1)
				callbacks[0](2)
			} else {
				callbacks[0](1)
				callbacks[0](2)
				callbacks[0](3)
				callbacks[0](4)
			}
			if !tracker.EventBuckets().Snapshot().Status.Degraded {
				t.Fatal("observation failure hidden")
			}
			if mode == "capacity" {
				assertV1(t, tracker, [2]int64{10, 0})
			} else if mode == "negative" {
				assertV1(t, tracker, [2]int64{1, 0})
			} else {
				tracker.usersMu.RLock()
				value := tracker.users[7].upload.Load()
				tracker.usersMu.RUnlock()
				if value != math.MinInt64 {
					t.Fatal("observer changed legacy overflow semantics")
				}
			}
		})
	}
}

func TestEventBucketsConcurrentActualReadWriteAndCuts(t *testing.T) {
	tracker := eventTracker(t, usagebucket.Options{MaxEvents: 256, MaxSealed: 64})
	connection, peer := lifecycleTCP(t, tracker, "user")
	var workers sync.WaitGroup
	errorsCh := make(chan error, 4)
	workers.Add(4)
	go func() {
		defer workers.Done()
		for i := 0; i < 64; i++ {
			if _, err := peer.Write([]byte("u")); err != nil {
				errorsCh <- err
				return
			}
		}
	}()
	go func() {
		defer workers.Done()
		for i := 0; i < 64; i++ {
			if _, err := io.ReadFull(connection, make([]byte, 1)); err != nil {
				errorsCh <- err
				return
			}
		}
	}()
	go func() {
		defer workers.Done()
		for i := 0; i < 64; i++ {
			if _, err := connection.Write([]byte("d")); err != nil {
				errorsCh <- err
				return
			}
		}
	}()
	go func() {
		defer workers.Done()
		for i := 0; i < 64; i++ {
			if _, err := io.ReadFull(peer, make([]byte, 1)); err != nil {
				errorsCh <- err
				return
			}
		}
	}()
	for i := 0; i < 16; i++ {
		if !tracker.EventBuckets().Cut(usagebucket.ManualFence, false) {
			t.Fatal("cut")
		}
	}
	workers.Wait()
	close(errorsCh)
	for err := range errorsCh {
		t.Fatal(err)
	}
	assertV1(t, tracker, [2]int64{64, 64})
	events := countEvents(tracker)
	if len(events) != 128 {
		t.Fatalf("I/O accounting duplicated/lost: %d", len(events))
	}
	seen := map[uint64]bool{}
	for _, event := range events {
		if seen[event.Sequence] {
			t.Fatal("duplicate sequence")
		}
		seen[event.Sequence] = true
	}
}
