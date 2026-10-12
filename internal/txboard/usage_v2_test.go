package txboard

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"testing"

	"github.com/ANRCM0/TX-Node/internal/config"
	"github.com/ANRCM0/TX-Node/internal/usagev2"
)

func usageCapability() usagev2.Session {
	return usagev2.Session{ProtocolVersion: 2, CoreInstanceID: "core_instance_0001", NodeID: "42", SourceEpoch: "1", ReceiptGeneration: "1", RetentionSeconds: 86400, MaxLateReportSeconds: 43200, ReceiptRetentionSeconds: 172800, IssuedAt: 100, Grants: []usagev2.Grant{{UserID: "7", AccessSubjectID: "9001", AuthSnapshotID: "7001", TariffRevisionID: "301", TermsID: "6001", UsagePeriodID: "8001", IssuedAt: 100, LeaseExpiresAt: 1000, RevocationVersion: "0"}}}
}

func TestNativeV2RemainsOptionalAndFailClosedWithoutCapability(t *testing.T) {
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		json.NewEncoder(w).Encode(map[string]any{"data": map[string]any{"protocol_version": 1, "node_id": 42, "usage_v2": map[string]any{"unsupported": "ignored-by-v1"}}})
	}))
	defer server.Close()
	c := NewClient(config.PanelConfig{URL: server.URL, Token: "native-secret", NodeID: 42})
	c.http = server.Client()
	hs, err := c.Handshake()
	if err != nil {
		t.Fatal("optional invalid v2 object broke v1 handshake", err)
	}
	if _, err := c.NewUsageV2Reporter(hs, t.TempDir(), filepath.Join(t.TempDir(), "old.pending"), true); err == nil {
		t.Fatal("v2 enabled without valid capability")
	}
	if _, err := c.NewUsageV2Reporter(&Handshake{ProtocolVersion: 1}, t.TempDir(), "", true); err == nil {
		t.Fatal("missing capability enabled v2")
	}
}

func TestNativeExplicitV2CollectorPreservesV1PendingHandoff(t *testing.T) {
	if runtime.GOOS != "linux" {
		t.Skip("verified Linux spool required")
	}
	capability := usageCapability()
	capJSON, _ := json.Marshal(capability)
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer native-secret" || r.Header.Get("X-TX-Node-ID") != "42" {
			t.Error("native auth missing")
		}
		if r.URL.Path == usagev2.HTTPPrefix+"/batch/get" {
			w.WriteHeader(404)
			w.Write([]byte(`{"error":{"code":"BATCH_NOT_FOUND"}}`))
			return
		}
		if r.URL.Path != usagev2.HTTPPrefix+"/submit" {
			t.Error("unexpected path", r.URL.Path)
		}
		var submitted usagev2.Submission
		if err := json.NewDecoder(r.Body).Decode(&submitted); err != nil {
			t.Error(err)
		}
		json.NewEncoder(w).Encode(map[string]any{"data": usagev2.Receipt{ProtocolVersion: 2, CoreInstanceID: capability.CoreInstanceID, ReceiptGeneration: "1", NodeID: "42", SourceEpoch: "1", BatchID: submitted.Payload.BatchID, PayloadDigest: submitted.PayloadDigest, ReceiptStatus: "persisted", SettlementStatus: "pending", AcceptedAt: 201, ReceiptID: "receipt_42_000001"}})
	}))
	defer server.Close()
	c := NewClient(config.PanelConfig{URL: server.URL, Token: "native-secret", NodeID: 42})
	c.http = server.Client()
	hs := &Handshake{ProtocolVersion: 1, UsageV2: capJSON}
	path := filepath.Join(t.TempDir(), "v1.pending.json")
	if _, err := c.NewUsageV2Reporter(hs, t.TempDir(), path, false); err == nil {
		t.Fatal("nonempty counters crossed handoff")
	}
	if err := os.WriteFile(path, []byte(`{"old":"unchanged"}`), 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := c.NewUsageV2Reporter(hs, t.TempDir(), path, true); err == nil {
		t.Fatal("v1 pending relabeled")
	}
	if b, _ := os.ReadFile(path); string(b) != `{"old":"unchanged"}` {
		t.Fatal("v1 pending changed")
	}
	os.Remove(path)
	reporter, err := c.NewUsageV2Reporter(hs, t.TempDir(), path, true)
	if err != nil {
		t.Fatal(err)
	}
	defer reporter.Close()
	if _, err := reporter.Capture(100, 200, map[int][2]int64{7: {10, 20}}); err != nil {
		t.Fatal(err)
	}
	if err := reporter.Deliver(context.Background()); err != nil {
		t.Fatal(err)
	}
}
