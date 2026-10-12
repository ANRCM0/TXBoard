package audit

import (
	"net/http"
	"testing"
)

func newTargetIPTestReporter(reportAll bool) *Reporter {
	return &Reporter{
		cfg: Config{
			Enabled:   true,
			ReportAll: reportAll,
			QueueCap:  16,
		},
		http: &http.Client{},
	}
}

func TestObserveWithTargetIPKeepsLogicalTargetSeparate(t *testing.T) {
	r := newTargetIPTestReporter(true)

	r.ObserveWithTargetIP(42, "API.Example.COM", "203.0.113.9", "198.51.100.7")

	if len(r.queue) != 1 {
		t.Fatalf("expected one queued event, got %d", len(r.queue))
	}
	e := r.queue[0]
	if e.UserID != 42 {
		t.Fatalf("unexpected user id: %d", e.UserID)
	}
	if e.Target != "api.example.com" {
		t.Fatalf("logical target changed unexpectedly: %q", e.Target)
	}
	if e.TargetIP != "203.0.113.9" {
		t.Fatalf("unexpected target ip: %q", e.TargetIP)
	}
	if e.SourceIP != "198.51.100.7" {
		t.Fatalf("unexpected source ip: %q", e.SourceIP)
	}
}

func TestObserveBackwardCompatibilityLeavesTargetIPEmpty(t *testing.T) {
	r := newTargetIPTestReporter(true)

	r.Observe(7, "example.com", "198.51.100.8")

	if len(r.queue) != 1 {
		t.Fatalf("expected one queued event, got %d", len(r.queue))
	}
	if got := r.queue[0].TargetIP; got != "" {
		t.Fatalf("legacy Observe must not invent target_ip, got %q", got)
	}
}

func TestTargetIPDoesNotParticipateInRuleMatching(t *testing.T) {
	r := newTargetIPTestReporter(false)
	rules := []rule{{
		ID:        1,
		MatchType: "ip_cidr",
		values:    []string{"203.0.113.0/24"},
	}}
	r.rules.Store(&rules)

	// target_ip matches the CIDR, but the logical target does not. The event
	// must therefore remain unmatched; target_ip is observability metadata,
	// not a replacement for the established target rule semantics.
	r.ObserveWithTargetIP(9, "example.com", "203.0.113.9", "198.51.100.9")

	if len(r.queue) != 0 {
		t.Fatalf("target_ip must not affect rule matching; queued %d event(s)", len(r.queue))
	}
}
