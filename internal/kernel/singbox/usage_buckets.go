package singbox

import "github.com/ANRCM0/TX-Node/internal/usagebucket"

// NewConnTrackerWithEventBuckets is explicit local observation opt-in. The
// ordinary constructor and Service path remain unchanged. No billing is enabled.
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
