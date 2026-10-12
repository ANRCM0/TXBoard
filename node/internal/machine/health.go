package machine

import "sync"

// NodeHealth is an aggregated snapshot of the nodes managed by one orchestrator.
//
// Total is the number of nodes the panel currently expects this machine to run;
// Failed is the subset of those that are parked in backoff after a failed start.
type NodeHealth struct {
	Total  int
	Failed int
}

// HealthRegistry aggregates per-instance node health for the whole process so
// that the /healthz endpoint can report a degraded state when nodes keep
// failing. Without this, a machine whose every node failed to bind its port
// would still answer 200 and look perfectly healthy to any supervisor.
type HealthRegistry struct {
	mu    sync.RWMutex
	items map[string]NodeHealth
}

// NewHealthRegistry returns an empty registry.
func NewHealthRegistry() *HealthRegistry {
	return &HealthRegistry{items: make(map[string]NodeHealth)}
}

// Report records the health of a single instance, replacing any earlier value.
func (r *HealthRegistry) Report(instanceID string, h NodeHealth) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.items[instanceID] = h
}

// Remove drops an instance, used when an orchestrator shuts down.
func (r *HealthRegistry) Remove(instanceID string) {
	r.mu.Lock()
	defer r.mu.Unlock()
	delete(r.items, instanceID)
}

// Aggregate sums every reported instance. reported is false when no instance
// has ever reported, in which case callers must not degrade health — a
// node-mode deployment (no orchestrator at all) is not the same as a broken one.
func (r *HealthRegistry) Aggregate() (agg NodeHealth, reported bool) {
	r.mu.Lock()
	defer r.mu.Unlock()
	for _, h := range r.items {
		agg.Total += h.Total
		agg.Failed += h.Failed
		reported = true
	}
	return agg, reported
}

// Reset clears the registry. Tests only.
func (r *HealthRegistry) Reset() {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.items = make(map[string]NodeHealth)
}

// globalHealth is the process-wide registry. A process normally runs a single
// machine instance; keeping it global lets /healthz read it without threading a
// handle through every constructor and config reload path.
var globalHealth = NewHealthRegistry()

// GlobalHealth returns the process-wide registry.
func GlobalHealth() *HealthRegistry { return globalHealth }
