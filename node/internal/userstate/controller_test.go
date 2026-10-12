package userstate

import (
	"testing"

	"github.com/ANRCM0/TX-Node/internal/limiter"
	"github.com/ANRCM0/TX-Node/internal/model"
)

func newController() (*Controller, *limiter.SpeedTracker) {
	l := limiter.New()
	speed := limiter.NewSpeedTracker(l)
	return New(l, speed), speed
}

func TestReplaceCopiesStateAndUpdatesLimiterIndexes(t *testing.T) {
	controller, speed := newController()
	users := []model.UserSpec{
		{ID: 1, UUID: "user-1", SpeedLimit: 8, DeviceLimit: 2},
	}

	transition := controller.Replace(users, "hash-1")
	if len(transition.Previous.Users) != 0 {
		t.Fatalf("unexpected previous users: %#v", transition.Previous.Users)
	}
	if controller.Hash() != "hash-1" || controller.Count() != 1 {
		t.Fatalf("unexpected state: %#v", controller.Snapshot())
	}
	if speed.GetLimiter("user-1") == nil {
		t.Fatal("expected speed limiter index to be ready after Replace")
	}

	users[0].UUID = "mutated"
	if got := controller.Users()[0].UUID; got != "user-1" {
		t.Fatalf("controller leaked caller slice mutation: %q", got)
	}
}

func TestReplaceReturnsRemovedUsersAndRestoreRollsBack(t *testing.T) {
	controller, _ := newController()
	controller.Replace([]model.UserSpec{
		{ID: 1, UUID: "old"},
		{ID: 2, UUID: "keep"},
	}, "old-hash")

	transition := controller.Replace([]model.UserSpec{
		{ID: 2, UUID: "keep"},
	}, "new-hash")
	if len(transition.Removed) != 1 || transition.Removed[0] != 1 {
		t.Fatalf("unexpected removed IDs: %#v", transition.Removed)
	}

	controller.Restore(transition.Previous)
	snapshot := controller.Snapshot()
	if snapshot.Hash != "old-hash" || len(snapshot.Users) != 2 {
		t.Fatalf("restore failed: %#v", snapshot)
	}
}

func TestMergePreservesExistingOrderAndReplacesByID(t *testing.T) {
	controller, _ := newController()
	controller.Replace([]model.UserSpec{
		{ID: 1, UUID: "a"},
		{ID: 2, UUID: "b"},
	}, "h1")

	merged := controller.Merge([]model.UserSpec{
		{ID: 2, UUID: "b2"},
		{ID: 3, UUID: "c"},
	})
	if len(merged) != 3 {
		t.Fatalf("merged users = %#v", merged)
	}
	if merged[0].ID != 1 || merged[1].ID != 2 || merged[1].UUID != "b2" || merged[2].ID != 3 {
		t.Fatalf("unexpected merge order/content: %#v", merged)
	}
}

func TestSubtractUsesUserID(t *testing.T) {
	controller, _ := newController()
	controller.Replace([]model.UserSpec{
		{ID: 1, UUID: "a"},
		{ID: 2, UUID: "b"},
		{ID: 3, UUID: "c"},
	}, "h1")

	filtered := controller.Subtract([]model.UserSpec{{ID: 2, UUID: "ignored"}})
	if len(filtered) != 2 || filtered[0].ID != 1 || filtered[1].ID != 3 {
		t.Fatalf("unexpected subtract result: %#v", filtered)
	}
}

func TestSnapshotsAreImmutableCopies(t *testing.T) {
	controller, _ := newController()
	controller.Replace([]model.UserSpec{{ID: 1, UUID: "original"}}, "hash")

	first := controller.Snapshot()
	first.Users[0].UUID = "mutated"

	second := controller.Snapshot()
	if second.Users[0].UUID != "original" {
		t.Fatalf("snapshot mutation leaked into controller: %#v", second.Users)
	}
}
