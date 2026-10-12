package usagebucket

import (
	"context"
	"sync"
	"time"
)

// ProcessOwner is a process-lifetime single assignment. Closing or failing a
// consumer never invalidates it; a replacement requires a new process. It stores
// no identity, credential, meter or worker. Service uses one shared owner.
type ProcessOwner struct {
	mu      sync.Mutex
	claimed bool
}

func (o *ProcessOwner) Claim() bool {
	o.mu.Lock()
	defer o.mu.Unlock()
	if o.claimed {
		return false
	}
	o.claimed = true
	return true
}

func makeCoordinator(options CoordinatorOptions) (*Coordinator, error) {
	if options.MaxMeters == 0 {
		options.MaxMeters = 8
	}
	if options.MaxMeters < 1 || options.MaxMeters > 8 {
		return nil, ErrCoordinatorCapacity
	}
	return &Coordinator{maxMeters: options.MaxMeters, wake: make(chan struct{}, 1), stop: make(chan struct{}), done: make(chan struct{}), status: CoordinatorStatus{Attribution: "unattributable", RestoreUnknown: true}}, nil
}

// NewAsyncCoordinator performs no filesystem work in its caller. Its one worker
// opens the store then persists bounded retained claims. There is no retry worker.
func NewAsyncCoordinator(dir string, storeOptions StoreOptions, options CoordinatorOptions) (*Coordinator, error) {
	return newAsyncCoordinator(options, func() (*Store, error) { return OpenStore(dir, storeOptions) })
}

func newAsyncCoordinator(options CoordinatorOptions, opener func() (*Store, error)) (*Coordinator, error) {
	c, err := makeCoordinator(options)
	if err != nil {
		return nil, err
	}
	c.runtime = true
	c.opener = opener
	c.status.Opening = true
	go c.run()
	return c, nil
}

// Retire is invoked after the original kernel's Close and cancellation. Merely
// replacing a tracker or observing ActiveCount zero cannot begin the grace.
func (c *Coordinator) Retire(meter *Meter) {
	if meter == nil {
		return
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	for _, registered := range c.meters {
		if registered == meter {
			meter.retire()
			return
		}
	}
}

// LocalFence is a bounded in-memory lifecycle notification, never disk work or
// a claim about Core authorization. Old meters keep their original connections.
func (c *Coordinator) LocalFence(advance bool) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.status.Closing || c.status.Closed || c.err != nil {
		return
	}
	for _, meter := range c.meters {
		meter.Cut(LifecycleFence, advance)
	}
	select {
	case c.wake <- struct{}{}:
	default:
	}
}

// StoreSnapshot is a fast status read; filesystem work does not hold these locks.
func (c *Coordinator) StoreSnapshot() (StoreStatus, bool) {
	c.mu.Lock()
	store := c.store
	c.mu.Unlock()
	if store == nil {
		return StoreStatus{}, false
	}
	return store.Status(), true
}

// ReadRecord is an explicit local evidence reader. Runtime count/lifecycle hooks
// never invoke it. It cannot acknowledge meter data or activate reporting.
func (c *Coordinator) ReadRecord(sequence uint64) (StoredRecord, error) {
	c.mu.Lock()
	store := c.store
	c.mu.Unlock()
	if store == nil {
		return StoredRecord{}, ErrStoreClosed
	}
	return store.Read(sequence)
}

func (c *Coordinator) pollRuntime() bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.status.Closing && !time.Now().Before(c.deadline) {
		return true
	}
	for index := 0; index < len(c.meters); {
		meter := c.meters[index]
		meter.tick()
		if meter.drained() {
			c.status.RetainedEventCapacity -= meter.maxEvents * (meter.maxSealed + 1)
			copy(c.meters[index:], c.meters[index+1:])
			c.meters[len(c.meters)-1] = nil
			c.meters = c.meters[:len(c.meters)-1]
			continue
		}
		index++
	}
	c.status.Meters = len(c.meters)
	if len(c.meters) == 0 {
		c.next = 0
	} else {
		c.next %= len(c.meters)
	}
	return c.status.Closing && len(c.meters) == 0
}

func (c *Coordinator) closeRuntime(ctx context.Context) error {
	ctx, cancel := context.WithTimeout(ctx, 2*time.Second)
	defer cancel()
	c.mu.Lock()
	if !c.status.Closing {
		c.status.Closing = true
		c.deadline, _ = ctx.Deadline()
		if ctx.Err() != nil {
			c.deadline = time.Now()
		}
		for _, meter := range c.meters {
			meter.finishForShutdown()
		}
		select {
		case c.wake <- struct{}{}:
		default:
		}
	}
	c.mu.Unlock()
	select {
	case <-ctx.Done():
		c.mu.Lock()
		if !c.halted {
			c.halted = true
			close(c.stop)
		}
		c.mu.Unlock()
		return ctx.Err()
	case <-c.done:
		c.mu.Lock()
		defer c.mu.Unlock()
		return c.err
	}
}
