package singbox

import (
	"time"

	"github.com/ANRCM0/TX-Node/internal/usagebucket"
)

// NewConnTrackerWithEventBuckets is explicit local observation opt-in. The
// ordinary constructor remains unchanged. No billing is enabled.
func NewConnTrackerWithEventBuckets(options usagebucket.Options) (*ConnTracker, error) {
	meter, err := usagebucket.New(options)
	if err != nil {
		return nil, err
	}
	tracker := NewConnTracker(0)
	tracker.eventBuckets = meter
	return tracker, nil
}

func (t *ConnTracker) EventBuckets() *usagebucket.Meter { return t.eventBuckets }

// SetLocalEventConsumer is an optional local capability, not part of Kernel's
// required interface. Its owner is fixed before the first successful Start.
func (s *SingBox) SetLocalEventConsumer(consumer *usagebucket.Coordinator) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	if consumer == nil || s.box != nil || s.localEventConsumer != nil {
		return false
	}
	s.localEventConsumer = consumer
	return true
}

func newRuntimeConnTracker(consumer *usagebucket.Coordinator) *ConnTracker {
	if consumer == nil {
		return NewConnTracker(0)
	}
	tracker, err := NewConnTrackerWithEventBuckets(usagebucket.Options{Enabled: true, Window: time.Second, MaxEvents: 128, MaxSealed: 8})
	if err != nil {
		return NewConnTracker(0)
	}
	if err := consumer.Add(tracker.eventBuckets); err != nil {
		return NewConnTracker(0)
	}
	return tracker
}

func retireLocalTracker(consumer *usagebucket.Coordinator, tracker *ConnTracker) {
	if consumer != nil && tracker != nil {
		consumer.Retire(tracker.eventBuckets)
	}
}
