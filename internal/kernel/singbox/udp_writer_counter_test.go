package singbox

import (
	"context"
	"errors"
	"io"
	"net"
	"testing"
	"time"

	"github.com/ANRCM0/TX-Node/internal/usagebucket"
	"github.com/sagernet/sing/common/buf"
	"github.com/sagernet/sing/common/bufio"
	singM "github.com/sagernet/sing/common/metadata"
	N "github.com/sagernet/sing/common/network"
	"golang.org/x/time/rate"
)

type udpWriterFixture struct {
	source   net.PacketConn
	sender   net.PacketConn
	receiver net.PacketConn
	reader   *onePacketConn
	writer   *trackedPacketConn
}

func newUDPWriterFixture(t *testing.T, tracker *ConnTracker) udpWriterFixture {
	t.Helper()
	open := func() net.PacketConn {
		connection, err := net.ListenPacket("udp", "127.0.0.1:0")
		if err != nil {
			t.Fatal(err)
		}
		t.Cleanup(func() { _ = connection.Close() })
		if err := connection.SetDeadline(time.Now().Add(3 * time.Second)); err != nil {
			t.Fatal(err)
		}
		return connection
	}
	source, sender, receiver, writer := open(), open(), open(), open()
	reader := &onePacketConn{PacketConn: bufio.NewPacketConn(source), remaining: 1}
	rawWriter := &onePacketConn{PacketConn: bufio.NewPacketConn(writer), destination: singM.SocksaddrFromNet(receiver.LocalAddr())}
	tracked := tracker.RoutedPacketConnection(context.Background(), rawWriter, testInboundContext("user", "127.0.0.1"), nil, nil).(*trackedPacketConn)
	t.Cleanup(func() { _ = tracked.Close() })
	return udpWriterFixture{source, sender, receiver, reader, tracked}
}

func udpWriterTracker(t *testing.T, observed bool) *ConnTracker {
	t.Helper()
	if observed {
		return eventTracker(t, usagebucket.Options{})
	}
	tracker := NewConnTracker(0)
	tracker.SetUserMap(map[string]int{"user": 7})
	return tracker
}

func (fixture udpWriterFixture) send(t *testing.T, payload string) {
	t.Helper()
	if _, err := fixture.sender.WriteTo([]byte(payload), fixture.source.LocalAddr()); err != nil {
		t.Fatal(err)
	}
}

func (fixture udpWriterFixture) receive(t *testing.T, payload string) {
	t.Helper()
	data := make([]byte, 64)
	n, _, err := fixture.receiver.ReadFrom(data)
	if err != nil || string(data[:n]) != payload {
		t.Fatalf("delivered packet %q %v, want %q", data[:n], err, payload)
	}
}

type cachedUDPReader struct {
	N.PacketReader
	packets []*N.PacketBuffer
}

type udpCopyFailureReader struct {
	N.PacketReader
	failure error
}

func (reader *udpCopyFailureReader) HandshakeFailure(err error) error {
	reader.failure = err
	return nil
}

func (reader *cachedUDPReader) ReadCachedPacket() *N.PacketBuffer {
	if len(reader.packets) == 0 {
		return nil
	}
	packet := reader.packets[0]
	reader.packets = reader.packets[1:]
	return packet
}

func assertUDPCopyWriterEvents(t *testing.T, tracker *ConnTracker, observed bool, packets int, bytes int64) {
	t.Helper()
	assertV1(t, tracker, [2]int64{bytes, 0}) // Preserve the existing callback direction.
	if !observed {
		if tracker.EventBuckets() != nil {
			t.Fatal("default writer enabled observation")
		}
		return
	}
	events := countEvents(tracker)
	if len(events) != packets {
		t.Fatalf("writer count owners: %d, want %d", len(events), packets)
	}
	var total int64
	for _, event := range events {
		total += event.RawCount
		if event.Path != usagebucket.UDPWriteCallback || event.PhysicalDirection != usagebucket.Download || event.LegacyDirection != usagebucket.Upload || !event.DirectionMismatch || !event.IntervalUnknown || event.HasIOStart {
			t.Fatalf("writer callback direction/timing changed: %+v", event)
		}
	}
	if total != bytes {
		t.Fatalf("observer counted %d bytes, want %d", total, bytes)
	}
}

func TestUDPPacketWriterCopyCountsOnceWithDefaultAndObservedTrackers(t *testing.T) {
	for _, observed := range []bool{false, true} {
		for _, cached := range []bool{false, true} {
			name := map[bool]string{false: "default", true: "observed"}[observed] + "/" + map[bool]string{false: "live", true: "cached_and_live"}[cached]
			t.Run(name, func(t *testing.T) {
				tracker := udpWriterTracker(t, observed)
				fixture := newUDPWriterFixture(t, tracker)
				if N.UnwrapPacketWriter(fixture.writer) != fixture.writer {
					t.Fatal("plain writer traversal bypassed the tracked boundary")
				}
				raw, counters := N.UnwrapCountPacketWriter(fixture.writer, nil)
				if raw != fixture.writer.PacketConn || len(counters) != 1 {
					t.Fatal("counter-aware unwrap did not return one underlying writer owner")
				}
				if _, repeated := N.UnwrapCountPacketWriter(raw, counters); len(repeated) != 1 {
					t.Fatal("repeated underlying traversal added another owner")
				}
				var reader N.PacketReader = fixture.reader
				payloads := []string{"live-packet"}
				if cached {
					payloads = []string{"cached-one", "cached-two", "live-packet"}
					cachedReader := &cachedUDPReader{PacketReader: fixture.reader}
					for _, payload := range payloads[:2] {
						fixture.send(t, payload)
						packet := N.NewPacketBuffer()
						packet.Buffer = buf.NewPacket()
						var err error
						packet.Destination, err = fixture.reader.PacketConn.ReadPacket(packet.Buffer)
						if err != nil {
							packet.Buffer.Release()
							N.PutPacketBuffer(packet)
							t.Fatal(err)
						}
						cachedReader.packets = append(cachedReader.packets, packet)
					}
					reader = cachedReader
				}
				fixture.send(t, payloads[len(payloads)-1])
				var total int64
				for _, payload := range payloads {
					total += int64(len(payload))
				}
				n, err := bufio.CopyPacket(fixture.writer, reader)
				if n != total || !errors.Is(err, io.EOF) {
					t.Fatalf("pinned CopyPacket %d %v, want %d/EOF", n, err, total)
				}
				for _, payload := range payloads {
					fixture.receive(t, payload)
				}
				assertUDPCopyWriterEvents(t, tracker, observed, len(payloads), total)
			})
		}
	}
}

func TestUDPPacketWriterFailedCopyDoesNotCountAttemptedBytes(t *testing.T) {
	for _, observed := range []bool{false, true} {
		t.Run(map[bool]string{false: "default", true: "observed"}[observed], func(t *testing.T) {
			tracker := udpWriterTracker(t, observed)
			fixture := newUDPWriterFixture(t, tracker)
			if err := fixture.writer.Close(); err != nil {
				t.Fatal(err)
			}
			fixture.send(t, "not-delivered")
			reader := &udpCopyFailureReader{PacketReader: fixture.reader}
			n, err := bufio.CopyPacket(fixture.writer, reader)
			if n != 0 || !errors.Is(err, net.ErrClosed) || !errors.Is(reader.failure, net.ErrClosed) {
				t.Fatalf("closed CopyPacket %d %v", n, err)
			}
			assertUDPCopyWriterEvents(t, tracker, observed, 0, 0)
		})
	}
}

func TestUDPPacketWriterCopyCloseCancelsPostWriteRateWait(t *testing.T) {
	for _, observed := range []bool{false, true} {
		t.Run(map[bool]string{false: "default", true: "observed"}[observed], func(t *testing.T) {
			tracker := udpWriterTracker(t, observed)
			limit := rate.NewLimiter(0.001, 1)
			tracker.SetSpeedLimitFunc(func(string) *rate.Limiter { return limit })
			fixture := newUDPWriterFixture(t, tracker)
			if !limit.AllowN(time.Now(), 1) {
				t.Fatal("initial rate token missing")
			}
			fixture.send(t, "x")
			type result struct {
				n   int64
				err error
			}
			finished := make(chan result, 1)
			go func() { n, err := bufio.CopyPacket(fixture.writer, fixture.reader); finished <- result{n, err} }()
			fixture.receive(t, "x") // The callback limiter runs after successful I/O.
			deadline := time.Now().Add(time.Second)
			for limit.Tokens() >= 0 && time.Now().Before(deadline) {
				time.Sleep(time.Millisecond)
			}
			if limit.Tokens() >= 0 {
				t.Fatal("copy bypassed its post-write limiter")
			}
			select {
			case result := <-finished:
				t.Fatalf("rate wait returned before close: %+v", result)
			default:
			}
			if err := fixture.writer.Close(); err != nil {
				t.Fatal(err)
			}
			select {
			case result := <-finished:
				if result.n != 1 || !errors.Is(result.err, io.EOF) {
					t.Fatalf("cancelled copy %+v", result)
				}
			case <-time.After(time.Second):
				t.Fatal("Close did not cancel writer callback rate wait")
			}
			assertUDPCopyWriterEvents(t, tracker, observed, 1, 1)
		})
	}
}

func TestUDPPacketWriterPlainUnwrapKeepsPreWriteRateCancellation(t *testing.T) {
	tracker := udpWriterTracker(t, false)
	limit := rate.NewLimiter(0.001, 1)
	tracker.SetSpeedLimitFunc(func(string) *rate.Limiter { return limit })
	fixture := newUDPWriterFixture(t, tracker)
	if !limit.AllowN(time.Now(), 1) {
		t.Fatal("initial rate token missing")
	}
	finished := make(chan error, 1)
	go func() {
		packet := buf.As([]byte("x"))
		defer packet.Release()
		finished <- N.UnwrapPacketWriter(fixture.writer).WritePacket(packet, singM.SocksaddrFromNet(fixture.receiver.LocalAddr()))
	}()
	deadline := time.Now().Add(time.Second)
	for limit.Tokens() >= 0 && time.Now().Before(deadline) {
		time.Sleep(time.Millisecond)
	}
	if limit.Tokens() >= 0 {
		t.Fatal("plain unwrap bypassed pre-write limiter")
	}
	if err := fixture.writer.Close(); err != nil {
		t.Fatal(err)
	}
	select {
	case err := <-finished:
		if !errors.Is(err, context.Canceled) {
			t.Fatalf("direct rate cancellation %v", err)
		}
	case <-time.After(time.Second):
		t.Fatal("plain writer Close did not cancel pre-write rate wait")
	}
	if err := fixture.receiver.SetReadDeadline(time.Now().Add(20 * time.Millisecond)); err != nil {
		t.Fatal(err)
	}
	if n, _, err := fixture.receiver.ReadFrom(make([]byte, 1)); n != 0 || err == nil {
		t.Fatal("plain writer delivered a packet despite cancelled pre-write wait")
	}
	assertUDPCopyWriterEvents(t, tracker, false, 0, 0)
}
