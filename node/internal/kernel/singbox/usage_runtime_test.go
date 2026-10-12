//go:build linux

package singbox

import (
	"context"
	"io"
	"path/filepath"
	"testing"
	"time"

	"github.com/ANRCM0/TX-Node/internal/config"
	"github.com/ANRCM0/TX-Node/internal/usagebucket"
)

func runtimeConsumer(t *testing.T) *usagebucket.Coordinator {
	t.Helper()
	c, err := usagebucket.NewAsyncCoordinator(filepath.Join(t.TempDir(), "evidence"), usagebucket.StoreOptions{}, usagebucket.CoordinatorOptions{})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		ctx, cancel := context.WithTimeout(context.Background(), time.Second)
		defer cancel()
		_ = c.Close(ctx)
	})
	return c
}

func TestRuntimeTrackerFactoryNilConsumerAndFixedSingBoxAttachment(t *testing.T) {
	if tracker := newRuntimeConnTracker(nil); tracker.EventBuckets() != nil {
		t.Fatal("default runtime allocated local observation meter")
	}
	c := runtimeConsumer(t)
	s := New(config.KernelConfig{})
	if s.SetLocalEventConsumer(nil) || !s.SetLocalEventConsumer(c) || s.SetLocalEventConsumer(c) {
		t.Fatal("kernel local attachment was replaceable")
	}
	tracker := newRuntimeConnTracker(c)
	if tracker.EventBuckets() == nil || c.Status().Meters != 1 || c.Status().RetainedEventCapacity != 128*9 {
		t.Fatal("production factory did not use bounded meter options")
	}
}

func TestRuntimeRestartKeepsOldTrackerBindingAfterCloseAndActiveCountZero(t *testing.T) {
	c := runtimeConsumer(t)
	old := newRuntimeConnTracker(c)
	old.SetUserMap(map[string]int{"user": 7})
	connection, peer := lifecycleTCP(t, old, "user")
	_, callbacks := connection.(*trackedConn).UnwrapReader()
	oldCallback := callbacks[0]
	write := make(chan error, 1)
	go func() { _, err := peer.Write([]byte("old")); write <- err }()
	if _, err := io.ReadFull(connection, make([]byte, 3)); err != nil {
		t.Fatal(err)
	}
	if err := <-write; err != nil {
		t.Fatal(err)
	}
	_ = connection.Close()
	if old.ActiveCount() != 0 || old.EventBuckets().Status().Retiring {
		t.Fatal("ActiveCount zero was treated as close/cancel retirement")
	}
	fresh := newRuntimeConnTracker(c)
	fresh.SetUserMap(map[string]int{"user": 8})
	old.SetUserMap(map[string]int{"user": 8})
	retireLocalTracker(c, old) // models the hook AFTER box Close and cancel
	oldCallback(5)
	if !old.EventBuckets().Status().Retiring || old.EventBuckets().Status().Stopped || c.Status().Meters != 2 {
		t.Fatal("old tracker was dropped before grace and local confirmations")
	}
	ctx, cancel := context.WithTimeout(context.Background(), time.Second)
	defer cancel()
	if err := c.Close(ctx); err != nil {
		t.Fatal(err)
	}
	if old.EventBuckets().Status().PersistedBuckets == 0 || !old.EventBuckets().Status().Stopped || c.Status().Meters != 0 {
		t.Fatal("normal shutdown failed to durably drain old tracker")
	}
	store, _ := c.StoreSnapshot()
	var found bool
	for sequence := uint64(1); sequence <= store.Sequence; sequence++ {
		record, err := c.ReadRecord(sequence)
		if err != nil {
			t.Fatal(err)
		}
		if record.Bucket == nil {
			continue
		}
		for _, event := range record.Bucket.Events {
			if event.RawCount == 5 {
				found = event.UserID == 7 && event.LateAfterClose && event.IntervalUnknown && event.BindingGeneration == 1
			}
		}
	}
	if !found || old.users[7].upload.Load() != 8 {
		t.Fatal("late original callback changed binding or original v1 counter")
	}
}

func TestRuntimeFactoryRegistryOverflowFailsObservationAndKeepsV1Tracker(t *testing.T) {
	c := runtimeConsumer(t)
	var registered []*ConnTracker
	for i := 0; i < 8; i++ {
		registered = append(registered, newRuntimeConnTracker(c))
	}
	ordinary := newRuntimeConnTracker(c)
	if ordinary.EventBuckets() != nil || !c.Status().Degraded || c.Status().Meters != 8 {
		t.Fatal("registry overflow allocated another observation stream")
	}
	for _, tracker := range registered {
		if tracker.EventBuckets() == nil || !tracker.EventBuckets().Status().Degraded {
			t.Fatal("registered observation continued after capacity failure")
		}
	}
	ordinary.SetUserMap(map[string]int{"user": 7})
	connection, peer := lifecycleTCP(t, ordinary, "user")
	wrote := make(chan error, 1)
	go func() { _, err := peer.Write([]byte("v1")); wrote <- err }()
	if _, err := io.ReadFull(connection, make([]byte, 2)); err != nil {
		t.Fatal(err)
	}
	if err := <-wrote; err != nil {
		t.Fatal(err)
	}
	assertV1(t, ordinary, [2]int64{2, 0})
}
