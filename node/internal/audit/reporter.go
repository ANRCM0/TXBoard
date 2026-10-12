// Package audit implements the tx-node embedded access-audit reporter.
//
// The optional reporter observes sing-box kernel connections, matches targets
// using one collection/matching engine, with Xboard legacy-plugin and
// TXBoard native transports selected by the control-plane provider.
// The reporter does not introduce a separate credential.
//
// Disabled by default: without an [audit] section in config.yml, the reporter
// does not participate in request handling or traffic reporting.
package audit

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"net"
	"net/http"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/ANRCM0/TX-Node/internal/nlog"
)

const (
	rulesPath  = "/api/v1/plugin/access-audit/rules"
	reportPath = "/api/v1/plugin/access-audit/report"

	// maxEventsPerBatch mirrors the panel's MAX_EVENTS validation cap.
	// Batches larger than this are rejected with 422 (whole batch lost),
	// so clamp BatchMax instead and warn.
	maxEventsPerBatch = 500
)

// Default batch/queue sizes for the two reporting modes.
const (
	defaultBatchMax = 50
	defaultQueueCap = 5000
	// report_all carries far more traffic (every routed connection, not just
	// rule hits), so it gets larger defaults.
	reportAllBatchMax = 200
	reportAllQueueCap = 50000

	// maxBatchesPerFlush caps how many POSTs a single flush tick may send.
	// Together with BatchMax and FlushInterval it bounds panel load at
	// BatchMax*maxBatchesPerFlush/FlushInterval events per second per node.
	maxBatchesPerFlush = 10
	// flushBudget bounds wall-clock time spent inside one flush tick so the
	// ticker never drifts into the next interval.
	flushBudget = 12 * time.Second
)

// Config mirrors the `audit:` section of config.yml.
type Config struct {
	// Enabled gates the whole module. Default false.
	Enabled bool `yaml:"enabled"`
	// ReportAll queues every routed connection (not just rule hits) so the
	// panel gets a full access log. Default false = only rule-matched hits.
	ReportAll bool `yaml:"report_all"`
	// BatchMax events per report POST. Default 50 (200 when ReportAll).
	BatchMax int `yaml:"batch_max"`
	// FlushInterval seconds between report attempts. Default 15.
	FlushInterval int `yaml:"flush_interval"`
	// RulesRefresh minutes between rule pulls. Default 5.
	RulesRefresh int `yaml:"rules_refresh"`
	// QueueCap bounds memory when the panel is unreachable.
	// Default 5000 (50000 when ReportAll).
	QueueCap int `yaml:"queue_cap"`
}

// PanelAuth carries the provider-specific identity; the selected wire adapter
// determines header-based native authentication or legacy plugin auth.
type PanelAuth struct {
	Protocol  string // xboard (default) or txboard
	BaseURL   string
	Token     string
	NodeID    int
	NodeType  string
	MachineID int
}

type rule struct {
	ID         int    `json:"id"`
	Name       string `json:"name"`
	MatchType  string `json:"match_type"`
	MatchValue string `json:"match_value"`

	values []string // preprocessed (lowercased, split by newline/comma)
}

// Event is one audit hit. Legacy Xboard JSON omits the internal ID;
// TXBoard native wire emits it as event_id for server-side deduplication.
type Event struct {
	ID       string `json:"-"` // stable across retries; emitted only as native event_id
	UserID   int    `json:"user_id"`
	Target   string `json:"target"`
	TargetIP string `json:"target_ip,omitempty"`
	SourceIP string `json:"source_ip,omitempty"`
	Matched  bool   `json:"matched"` // 是否命中审计规则（report_all 模式下区分全量/命中）
}

// Stats exposes drop/backlog counters for observability.
//
// Dropped is the number of events discarded because the queue was full or a
// failed batch could not be requeued. It must never grow silently: a non-zero
// value means the panel is slower than the node's event rate.
type Stats struct {
	Dropped  uint64
	Reported uint64
	Failed   uint64
	Queued   int
}

// Reporter pulls rules, matches connection targets and batches reports.
// Safe for concurrent use. A nil *Reporter is valid and disabled.
//
// Locking protocol:
//   - rules is an immutable snapshot published via atomic.Pointer and read
//     lock-free by match(). refreshRules builds a fresh slice and Stores it;
//     snapshots are never mutated after publication.
//   - queueMu guards only the queue. This matters under report_all: every
//     new connection calls Observe → match(); when match() held the same
//     mutex as the queue, rule matching (linear scan) serialized connection
//     establishment against flush/requeue for no reason.
type Reporter struct {
	auth PanelAuth
	cfg  Config

	http *http.Client

	rules atomic.Pointer[[]rule]

	queueMu sync.Mutex
	queue   []Event

	// counters are atomics so Observe() stays lock-free for metrics reads.
	dropped  atomic.Uint64
	reported atomic.Uint64
	failed   atomic.Uint64
	// lastDropWarn throttles the "queue full" warning to one log per minute.
	lastDropWarn atomic.Int64
	// lastNoRulesWarn throttles the "no rules, nothing will be reported"
	// warning. refreshRules runs every RulesRefresh minutes (default 5); an
	// unthrottled warning would spam the log forever on a node that simply
	// chooses not to use rules.
	lastNoRulesWarn atomic.Int64
}

// resolveSizes applies defaults for batch/queue sizes.
//
// The caller's explicit values always win: we branch on the raw config BEFORE
// any default is written, so a user who explicitly sets batch_max: 50 (or
// queue_cap: 5000) keeps that value instead of being silently bumped to the
// report_all default.
func resolveSizes(cfg Config) (batchMax, queueCap int) {
	batchMax, queueCap = cfg.BatchMax, cfg.QueueCap
	if batchMax <= 0 {
		batchMax = defaultBatchMax
		if cfg.ReportAll {
			batchMax = reportAllBatchMax
		}
	}
	if queueCap <= 0 {
		queueCap = defaultQueueCap
		if cfg.ReportAll {
			queueCap = reportAllQueueCap
		}
	}
	return batchMax, queueCap
}

// New builds a Reporter. Returns a disabled (but non-nil) reporter when
// cfg.Enabled is false or the panel auth is incomplete.
func New(cfg Config, auth PanelAuth) *Reporter {
	r := &Reporter{cfg: cfg, auth: auth}
	if !cfg.Enabled {
		return r
	}
	if auth.BaseURL == "" || auth.Token == "" {
		nlog.Core().Warn("audit: enabled but panel url/token missing, disabled")
		return r
	}
	cfg.BatchMax, cfg.QueueCap = resolveSizes(cfg)
	maxBatch := maxEventsPerBatch
	if auth.Protocol == "txboard" && maxBatch > 200 { maxBatch = 200 }
	if cfg.BatchMax > maxBatch {
		nlog.Core().Warn("audit: batch_max exceeds panel limit, clamped",
			"configured", cfg.BatchMax, "clamped_to", maxBatch)
		cfg.BatchMax = maxBatch
	}
	if cfg.FlushInterval <= 0 {
		cfg.FlushInterval = 15
	}
	if cfg.RulesRefresh <= 0 {
		cfg.RulesRefresh = 5
	}
	r.cfg = cfg
	r.auth.BaseURL = strings.TrimRight(auth.BaseURL, "/")
	r.http = &http.Client{Timeout: 15 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error {return http.ErrUseLastResponse}}

	go r.loop()
	nlog.Core().Info("audit reporter enabled",
		"panel", r.auth.BaseURL, "node_id", auth.NodeID, "machine_id", auth.MachineID,
		"report_all", cfg.ReportAll, "batch_max", cfg.BatchMax, "queue_cap", cfg.QueueCap,
		"flush_interval", cfg.FlushInterval, "max_send_rate",
		fmt.Sprintf("%d events/%ds", cfg.BatchMax*maxBatchesPerFlush, cfg.FlushInterval))
	// Make the empty-rules trap loud. With report_all=false the ONLY way an
	// event reaches the panel is a rule hit, so a node with no enabled rules
	// reports nothing at all while still looking "enabled" in the config and
	// in the startup log above. Operators hit this constantly; warn up front
	// rather than leaving them to discover it from an empty dashboard
	// (refreshRules only logs at Debug, which production never shows).
	if !cfg.ReportAll {
		nlog.Core().Warn("audit: report_all=false — only rule-matched targets are " +
			"reported; with no enabled rules NOTHING will be sent. Set report_all: " +
			"true for a full access log, or add enable rules on the panel")
	}
	return r
}

// Enabled reports whether the module is active.
func (r *Reporter) Enabled() bool { return r != nil && r.http != nil }

// Stats returns a snapshot of the reporter counters.
func (r *Reporter) Stats() Stats {
	if r == nil {
		return Stats{}
	}
	r.queueMu.Lock()
	queued := len(r.queue)
	r.queueMu.Unlock()
	return Stats{
		Dropped:  r.dropped.Load(),
		Reported: r.reported.Load(),
		Failed:   r.failed.Load(),
		Queued:   queued,
	}
}

// Observe is the backward-compatible audit hook used by older kernel callers.
// It preserves the original target/source-IP API and leaves TargetIP empty.
func (r *Reporter) Observe(userID int, target, sourceIP string) {
	r.ObserveWithTargetIP(userID, target, "", sourceIP)
}

// ObserveWithTargetIP records the logical target separately from the exact
// destination IP selected by the outbound dialer when one is available. Rule
// matching intentionally continues to use only target so existing domain/IP
// audit rules keep their current semantics.
func (r *Reporter) ObserveWithTargetIP(userID int, target, targetIP, sourceIP string) {
	if !r.Enabled() || userID <= 0 || target == "" {
		return
	}
	target = strings.ToLower(strings.TrimSpace(target))
	if target == "" {
		return
	}
	targetIP = strings.TrimSpace(targetIP)
	matched := r.match(target)
	if !matched && !r.cfg.ReportAll {
		return
	}
	r.queueMu.Lock()
	if len(r.queue) < r.cfg.QueueCap {
		var id [16]byte
	if _, err := rand.Read(id[:]); err != nil {
		r.queueMu.Unlock()
		r.recordDrop(1, "failed generating audit observation ID")
		return
	}
	r.queue = append(r.queue, Event{ID: hex.EncodeToString(id[:]), UserID: userID, Target: target, TargetIP: targetIP, SourceIP: sourceIP, Matched: matched})
		r.queueMu.Unlock()
		return
	}
	// Queue full: drop the event, but never silently — a noisy panel must be
	// visible in the logs and in Stats().Dropped.
	queued := len(r.queue)
	r.queueMu.Unlock()

	dropped := r.dropped.Add(1)
	now := time.Now().Unix()
	if last := r.lastDropWarn.Load(); now-last >= 60 &&
		r.lastDropWarn.CompareAndSwap(last, now) {
		nlog.Core().Warn("audit: queue full, dropping events",
			"queue_cap", r.cfg.QueueCap, "queued", queued,
			"dropped_total", dropped, "report_all", r.cfg.ReportAll)
	}
}

// loop periodically refreshes rules and flushes the queue.
func (r *Reporter) loop() {
	r.refreshRules()
	refresh := time.NewTicker(time.Duration(r.cfg.RulesRefresh) * time.Minute)
	flush := time.NewTicker(time.Duration(r.cfg.FlushInterval) * time.Second)
	defer refresh.Stop()
	defer flush.Stop()
	for {
		select {
		case <-refresh.C:
			r.refreshRules()
		case <-flush.C:
			r.flushAll()
		}
	}
}

// refreshRules fetches the active rules through the selected wire adapter.
// A failed refresh keeps the last validated rule snapshot, never overwrites it.
func (r *Reporter) refreshRules() {
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	rules, err := r.adapter().FetchRules(ctx)
	if err != nil {
		nlog.Core().Warn("audit: refresh rules failed", "error", err)
		return
	}
	for i := range rules {
		var vals []string
		for _, v := range strings.FieldsFunc(rules[i].MatchValue, func(c rune) bool {
			return c == '\n' || c == '\r' || c == ','
		}) {
			if v = strings.ToLower(strings.TrimSpace(v)); v != "" {
				vals = append(vals, v)
			}
		}
		rules[i].values = vals
	}
	// Publish an immutable snapshot; match() reads it lock-free.
	snapshot := rules
	r.rules.Store(&snapshot)
	nlog.Core().Debug("audit: rules refreshed", "count", len(rules))

	// Rules loaded but empty while report_all=false: every connection will be
	// dropped by Observe(). Surface it instead of failing silently — this is
	// exactly the state a freshly-enabled node lands in, and it is
	// indistinguishable from "working fine" on the panel (no rows appear).
	if len(rules) == 0 && !r.cfg.ReportAll {
		r.warnNoRules()
	}
}

// warnNoRules emits the "no enabled rules" warning at most once per minute.
// A node that legitimately runs without rules would otherwise log this on
// every rules_refresh tick (default: every 5 minutes, forever).
func (r *Reporter) warnNoRules() {
	now := time.Now().Unix()
	last := r.lastNoRulesWarn.Load()
	if now-last < 60 || !r.lastNoRulesWarn.CompareAndSwap(last, now) {
		return
	}
	nlog.Core().Warn("audit: panel returned 0 enabled rules and report_all=false — " +
		"all connections are being dropped locally, nothing is reported. " +
		"Enable rules on the panel or set report_all: true",
		"node_id", r.auth.NodeID)
}

// match checks target against cached rules. Semantics mirror the panel's
// PHP RuleMatcher. Lock-free: reads the latest immutable snapshot.
func (r *Reporter) match(target string) bool {
	p := r.rules.Load()
	if p == nil || len(*p) == 0 {
		return false
	}
	rules := *p
	isIP := net.ParseIP(target) != nil
	for i := range rules {
		rule := &rules[i]
		if isIP && rule.MatchType != "ip_cidr" && rule.MatchType != "keyword" {
			continue
		}
		for _, v := range rule.values {
			if matchOne(rule.MatchType, v, target) {
				return true
			}
		}
	}
	return false
}

func matchOne(matchType, value, target string) bool {
	switch matchType {
	case "domain":
		return target == value
	case "domain_suffix":
		return target == value || strings.HasSuffix(target, "."+value)
	case "keyword":
		return strings.Contains(target, value)
	case "ip_cidr":
		return ipInCIDR(target, value)
	}
	return false
}

func ipInCIDR(ip, cidr string) bool {
	parsed := net.ParseIP(ip)
	if parsed == nil {
		return false
	}
	if !strings.Contains(cidr, "/") {
		return ip == cidr
	}
	_, network, err := net.ParseCIDR(cidr)
	if err != nil {
		return false
	}
	return network.Contains(parsed)
}

// flushAll drains the queue with repeated batches until it is empty, the
// send budget is exhausted, or a batch fails.
//
// Why not a single batch per tick: with report_all the incoming rate can far
// exceed BatchMax/FlushInterval, so a one-shot flush guarantees an ever-growing
// backlog that eventually overflows QueueCap. Draining in a bounded loop keeps
// the backlog bounded on the node side and — because each POST carries up to
// BatchMax events — still bounds the panel-side request rate.
func (r *Reporter) flushAll() {
	deadline := time.Now().Add(flushBudget)
	for i := 0; i < maxBatchesPerFlush; i++ {
		if time.Now().After(deadline) {
			return
		}
		if !r.flushOnce() {
			// Empty queue, or a failure that already requeued the batch.
			return
		}
	}
	// Budget hit while events remain: report the residual backlog so the
	// operator can raise maxBatchesPerFlush / lower flush_interval.
	r.queueMu.Lock()
	backlog := len(r.queue)
	r.queueMu.Unlock()
	if backlog > 0 {
		nlog.Core().Warn("audit: flush budget exhausted with backlog",
			"backlog", backlog, "queue_cap", r.cfg.QueueCap,
			"batches_sent", maxBatchesPerFlush)
	}
}

// flushOnce posts up to BatchMax queued events.
// Returns true when a batch was successfully delivered.
func (r *Reporter) flushOnce() bool {
	r.queueMu.Lock()
	if len(r.queue) == 0 {
		r.queueMu.Unlock()
		return false
	}
	batch := r.queue
	if len(batch) > r.cfg.BatchMax {
		batch = batch[:r.cfg.BatchMax]
	}
	r.queue = r.queue[len(batch):]
	r.queueMu.Unlock()

	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	if err := r.adapter().Send(ctx, batch); err != nil {
		r.failed.Add(1)
		nlog.Core().Warn("audit: report failed, requeue", "error", err, "events", len(batch))
		r.requeue(batch)
		return false
	}
	r.reported.Add(uint64(len(batch)))
	nlog.Core().Debug("audit: reported", "events", len(batch))
	return true
}

// requeue puts a failed batch back at the head of the queue. If the queue
// cannot take it all, the overflow is dropped with an explicit counter bump —
// never silently.
func (r *Reporter) requeue(batch []Event) {
	r.queueMu.Lock()
	room := r.cfg.QueueCap - len(r.queue)
	if room <= 0 {
		r.queueMu.Unlock()
		r.recordDrop(len(batch), "queue full on requeue")
		return
	}
	if room >= len(batch) {
		r.queue = append(batch, r.queue...)
		r.queueMu.Unlock()
		return
	}
	// Keep the newest events (tail of the batch) — they are more actionable.
	dropped := len(batch) - room
	r.queue = append(batch[dropped:], r.queue...)
	r.queueMu.Unlock()
	r.recordDrop(dropped, "partial requeue overflow")
}

func (r *Reporter) recordDrop(n int, reason string) {
	if n <= 0 {
		return
	}
	total := r.dropped.Add(uint64(n))
	now := time.Now().Unix()
	if last := r.lastDropWarn.Load(); now-last >= 60 &&
		r.lastDropWarn.CompareAndSwap(last, now) {
		nlog.Core().Warn("audit: dropping events",
			"count", n, "reason", reason, "dropped_total", total)
	}
}

// String implements fmt.Stringer without leaking the token.
func (r *Reporter) String() string {
	if !r.Enabled() {
		return "audit: disabled"
	}
	s := r.Stats()
	ruleCount := 0
	if p := r.rules.Load(); p != nil {
		ruleCount = len(*p)
	}
	return fmt.Sprintf("audit: panel=%s node=%d queued=%d rules=%d reported=%d dropped=%d failed=%d",
		r.auth.BaseURL, r.auth.NodeID, s.Queued, ruleCount, s.Reported, s.Dropped, s.Failed)
}
