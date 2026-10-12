package userstate

import (
	"sync"

	"github.com/ANRCM0/TX-Node/internal/limiter"
	"github.com/ANRCM0/TX-Node/internal/model"
)

// Snapshot is the immutable desired-user runtime state currently accepted from
// the ControlPlane. It is distinct from Service.appliedState, which describes
// what the kernel has successfully applied.
type Snapshot struct {
	Users []model.UserSpec
	Hash  string
}

type Transition struct {
	Previous Snapshot
	Removed  []int
}

// Controller owns the desired user snapshot plus the limiter/speed-tracker
// indexes derived from that snapshot. It does not mutate the proxy kernel.
type Controller struct {
	limiter      *limiter.Limiter
	speedTracker *limiter.SpeedTracker

	mu    sync.RWMutex
	users []model.UserSpec
	hash  string
}

func New(l *limiter.Limiter, speed *limiter.SpeedTracker) *Controller {
	return &Controller{
		limiter:      l,
		speedTracker: speed,
	}
}

func (c *Controller) Snapshot() Snapshot {
	if c == nil {
		return Snapshot{}
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	return Snapshot{
		Users: append([]model.UserSpec(nil), c.users...),
		Hash:  c.hash,
	}
}

func (c *Controller) Users() []model.UserSpec {
	return c.Snapshot().Users
}

func (c *Controller) Hash() string {
	if c == nil {
		return ""
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.hash
}

func (c *Controller) Count() int {
	if c == nil {
		return 0
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	return len(c.users)
}

// Replace updates desired user state and all limiter indexes before Service
// attempts a kernel mutation. The previous snapshot is returned so Service can
// roll the desired state back if the kernel update/restart fails.
func (c *Controller) Replace(users []model.UserSpec, hash string) Transition {
	if c == nil {
		return Transition{}
	}
	if users == nil {
		users = []model.UserSpec{}
	}
	next := append([]model.UserSpec(nil), users...)

	c.mu.Lock()
	previous := Snapshot{
		Users: append([]model.UserSpec(nil), c.users...),
		Hash:  c.hash,
	}

	var removed []int
	if c.limiter != nil {
		removed = c.limiter.UpdateUsers(next)
	}
	if c.speedTracker != nil {
		c.speedTracker.UpdateBuckets()
	}

	c.users = next
	c.hash = hash
	c.mu.Unlock()

	return Transition{
		Previous: previous,
		Removed:  append([]int(nil), removed...),
	}
}

func (c *Controller) Restore(snapshot Snapshot) {
	if c == nil {
		return
	}
	users := append([]model.UserSpec(nil), snapshot.Users...)
	if users == nil {
		users = []model.UserSpec{}
	}

	c.mu.Lock()
	if c.limiter != nil {
		c.limiter.UpdateUsers(users)
	}
	if c.speedTracker != nil {
		c.speedTracker.UpdateBuckets()
	}
	c.users = users
	c.hash = snapshot.Hash
	c.mu.Unlock()
}

// Merge overlays delta users onto the current desired state, keyed by user ID.
func (c *Controller) Merge(delta []model.UserSpec) []model.UserSpec {
	base := c.Users()
	if delta == nil {
		return base
	}

	index := make(map[int]model.UserSpec, len(base)+len(delta))
	order := make([]int, 0, len(base)+len(delta))
	for _, user := range base {
		if _, exists := index[user.ID]; !exists {
			order = append(order, user.ID)
		}
		index[user.ID] = user
	}
	for _, user := range delta {
		if _, exists := index[user.ID]; !exists {
			order = append(order, user.ID)
		}
		index[user.ID] = user
	}

	out := make([]model.UserSpec, 0, len(index))
	for _, id := range order {
		out = append(out, index[id])
	}
	return out
}

// Subtract removes delta user IDs from the current desired state.
func (c *Controller) Subtract(delta []model.UserSpec) []model.UserSpec {
	base := c.Users()
	if len(base) == 0 || len(delta) == 0 {
		return base
	}

	remove := make(map[int]struct{}, len(delta))
	for _, user := range delta {
		remove[user.ID] = struct{}{}
	}

	out := make([]model.UserSpec, 0, len(base))
	for _, user := range base {
		if _, found := remove[user.ID]; !found {
			out = append(out, user)
		}
	}
	return out
}
