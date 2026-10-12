package controlplane

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/ANRCM0/TX-Node/internal/config"
	"github.com/ANRCM0/TX-Node/internal/panel"
)

// Machine-mode traffic reports must carry the same ID as the durable spool;
// otherwise retrying after an ambiguous HTTP response can count bytes twice.
func TestMachineReportForwardsTrafficBatchID(t *testing.T) {
	got := make(chan string, 1)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v2/server/report" {
			t.Errorf("unexpected report path: %s", r.URL.Path)
			http.NotFound(w, r)
			return
		}
		var payload struct {
			BatchID string `json:"traffic_batch_id"`
		}
		if err := json.NewDecoder(r.Body).Decode(&payload); err != nil {
			t.Errorf("decode report: %v", err)
			w.WriteHeader(http.StatusBadRequest)
			return
		}
		got <- payload.BatchID
		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	client := panel.NewClient(config.PanelConfig{URL: server.URL, Token: "test", MachineID: 16})
	cp := NewMachineXboardControlPlane(client, config.KernelConfig{}, nil, nil)
	if err := cp.Report(ReportPayload{
		BatchID: "durable-batch-123",
		Traffic: map[int][2]int64{5: {123, 456}},
	}); err != nil {
		t.Fatal(err)
	}
	select {
	case id := <-got:
		if id != "durable-batch-123" {
			t.Fatalf("machine report batch ID = %q, want durable-batch-123", id)
		}
	default:
		t.Fatal("report endpoint did not receive a traffic batch ID")
	}
}
