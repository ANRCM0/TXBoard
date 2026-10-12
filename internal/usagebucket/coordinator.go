package usagebucket

import (
	"context"
	"errors"
	"sync"
	"time"
)

var ErrCoordinatorCapacity = errors.New("local event coordinator capacity reached")

type CoordinatorOptions struct{ MaxMeters int }
type CoordinatorStatus struct {
	Meters                int
	RetainedEventCapacity int
	InFlight              bool
	Degraded              bool
	Reason                string
	Closing               bool
	Closed                bool
	Authorizes            bool
	Attribution           string
	RestoreUnknown        bool
	Opening               bool
}

// Coordinator has one worker, at most eight meter references and one wakeup
// slot. The retained queue stays inside each bounded meter until durable ACK.
// Runtime consumers use the same worker for asynchronous store opening.
type Coordinator struct {
	mu        sync.Mutex
	store     *Store
	maxMeters int
	meters    []*Meter
	next      int
	wake      chan struct{}
	stop      chan struct{}
	done      chan struct{}
	status    CoordinatorStatus
	err       error
	runtime   bool
	opener    func() (*Store, error)
	deadline  time.Time
	halted    bool
}

func NewCoordinator(store *Store, options CoordinatorOptions) (*Coordinator, error) {
	if store == nil {
		return nil, ErrStoreEvidence
	}
	c, err := makeCoordinator(options)
	if err != nil {
		return nil, err
	}
	if err := c.attachStore(store); err != nil {
		return nil, err
	}
	go c.run()
	return c, nil
}

func (c *Coordinator) attachStore(store *Store) error {
	store.statusMu.Lock()
	attached := store.attached
	store.statusMu.Unlock()
	if attached {
		return ErrStoreOwner
	}
	store.writeMu.Lock()
	if store.closed || store.failed != nil {
		store.writeMu.Unlock()
		return ErrStoreClosed
	}
	if store.owner != nil {
		store.writeMu.Unlock()
		return ErrStoreOwner
	}
	store.owner = c
	store.statusMu.Lock()
	store.attached = true
	store.statusMu.Unlock()
	store.writeMu.Unlock()
	c.mu.Lock()
	c.store = store
	c.status.Opening = false
	c.mu.Unlock()
	return nil
}

func (c *Coordinator) Add(meter *Meter) error {
	if meter == nil {
		return ErrStoreEvidence
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.status.Closing || c.status.Closed {
		return ErrStoreClosed
	}
	if c.err != nil {
		return c.err
	}
	for _, old := range c.meters {
		if old == meter {
			return nil
		}
	}
	meter.mu.Lock()
	if meter.consumer != nil && meter.consumer != c {
		meter.mu.Unlock()
		return ErrStoreOwner
	}
	meter.mu.Unlock()
	capacity := meter.maxEvents * (meter.maxSealed + 1)
	if len(c.meters) >= c.maxMeters || c.status.RetainedEventCapacity+capacity > 65536 {
		c.failLocked(ErrCoordinatorCapacity)
		meter.stopPersistence("COORDINATOR_CAPACITY_REACHED")
		return ErrCoordinatorCapacity
	}
	meter.mu.Lock()
	if meter.consumer != nil && meter.consumer != c {
		meter.mu.Unlock()
		return ErrStoreOwner
	}
	meter.consumer = c
	meter.mu.Unlock()
	c.meters = append(c.meters, meter)
	c.status.Meters = len(c.meters)
	c.status.RetainedEventCapacity += capacity
	select {
	case c.wake <- struct{}{}:
	default:
	}
	return nil
}

func (c *Coordinator) failLocked(err error) {
	if c.err != nil {
		return
	}
	c.err = err
	c.status.Degraded = true
	c.status.Reason = storeErrorReason(err)
	if errors.Is(err, ErrCoordinatorCapacity) {
		c.status.Reason = "COORDINATOR_CAPACITY_REACHED"
	}
	for _, meter := range c.meters {
		meter.stopPersistence(c.status.Reason)
	}
}

func (c *Coordinator) choose() (*bucketClaim, error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.err != nil {
		return nil, c.err
	}
	if c.status.Closing && (!c.runtime || !time.Now().Before(c.deadline)) {
		return nil, nil
	}
	for i := 0; i < len(c.meters); i++ {
		index := (c.next + i) % len(c.meters)
		meter := c.meters[index]
		if meter.Status().Degraded {
			err := ErrStoreEvidence
			c.failLocked(err)
			return nil, err
		}
		if claim := meter.claimOldest(c); claim != nil {
			c.next = (index + 1) % len(c.meters)
			c.status.InFlight = true
			return claim, nil
		}
	}
	return nil, nil
}

func (c *Coordinator) run() {
	defer func() {
		var err error
		if c.store != nil {
			c.store.writeMu.Lock()
			err = c.store.closeLocked()
			c.store.writeMu.Unlock()
		}
		c.mu.Lock()
		if err != nil {
			c.failLocked(err)
		}
		c.status.InFlight = false
		c.status.Closed = true
		c.status.Opening = false
		c.mu.Unlock()
		close(c.done)
	}()
	if c.opener != nil {
		store, err := c.opener()
		if err == nil {
			err = c.attachStore(store)
			if err != nil {
				_ = store.Close()
			}
		}
		if err != nil {
			c.mu.Lock()
			c.failLocked(err)
			c.mu.Unlock()
			return
		}
	}
	ticker := time.NewTicker(20 * time.Millisecond)
	defer ticker.Stop()
	for {
		select {
		case <-c.stop:
			return
		default:
		}
		if c.runtime {
			if c.pollRuntime() {
				return
			}
		}
		claim, err := c.choose()
		if err != nil {
			return
		}
		if claim == nil {
			select {
			case <-c.stop:
				return
			case <-ticker.C:
			case <-c.wake:
			}
			continue
		}
		receipt, err := c.store.persistClaim(claim)
		if err == nil {
			err = c.store.hook("before_ack")
		}
		if err == nil && !claim.meter.acknowledge(c, receipt) {
			err = ErrStoreEvidence
		}
		if err == nil {
			err = c.store.hook("after_ack")
		}
		if err != nil {
			c.store.writeMu.Lock()
			if c.store.failed == nil {
				c.store.failed = err
			}
			c.store.updateStatus()
			c.store.writeMu.Unlock()
		}
		c.mu.Lock()
		c.status.InFlight = false
		if err != nil {
			c.failLocked(err)
		}
		c.mu.Unlock()
		if err != nil {
			return
		}
	}
}

func (c *Coordinator) Status() CoordinatorStatus { c.mu.Lock(); defer c.mu.Unlock(); return c.status }

// A timeout leaves the one worker and lock alive while it finishes the existing
// syscall. It does not spawn a replacement, interrupt the FD, or permit retry.
func (c *Coordinator) Close(ctx context.Context) error {
	if c.runtime {
		return c.closeRuntime(ctx)
	}
	c.mu.Lock()
	if !c.status.Closing {
		c.status.Closing = true
		for _, meter := range c.meters {
			meter.stopPersistence("CONSUMER_CLOSED")
		}
		close(c.stop)
	}
	c.mu.Unlock()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-c.done:
		c.mu.Lock()
		defer c.mu.Unlock()
		return c.err
	}
}
