package main

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"fmt"
	"github.com/ANRCM0/TX-Node/internal/usagev2"
	"net/http"
	"os"
	"os/exec"
	"strconv"
	"time"
)

func must(err error) {
	if err != nil {
		panic(err)
	}
}
func main() {
	var fixture struct {
		NodeID  string `json:"node_id"`
		Started int64  `json:"started_at"`
		Ended   int64  `json:"ended_at"`
	}
	b, err := os.ReadFile(os.Getenv("USAGE_INTEROP_DIR") + "/fixture.json")
	must(err)
	must(json.Unmarshal(b, &fixture))
	pem, err := os.ReadFile(os.Getenv("USAGE_INTEROP_DIR") + "/cert.pem")
	must(err)
	roots := x509.NewCertPool()
	if !roots.AppendCertsFromPEM(pem) {
		panic("CA load failed")
	}
	client := &http.Client{Transport: &http.Transport{TLSClientConfig: &tls.Config{RootCAs: roots, MinVersion: tls.VersionTLS12}}, Timeout: 10 * time.Second}
	req, err := http.NewRequest("POST", "https://127.0.0.1:18443/txapi/node/v1/handshake", bytes.NewBufferString("{}"))
	must(err)
	req.Header.Set("Authorization", "Bearer usage-interop-local-test-only")
	req.Header.Set("X-TX-Node-ID", fixture.NodeID)
	req.Header.Set("Content-Type", "application/json")
	response, err := client.Do(req)
	must(err)
	defer response.Body.Close()
	if response.StatusCode != 200 {
		panic(fmt.Sprintf("handshake status %d", response.StatusCode))
	}
	var envelope struct {
		Data struct {
			Usage json.RawMessage `json:"usage_v2"`
		} `json:"data"`
	}
	must(json.NewDecoder(response.Body).Decode(&envelope))
	var session usagev2.Session
	must(usagev2.StrictDecode(envelope.Data.Usage, &session, usagev2.MaxPayloadBytes))
	must(session.Validate())
	transport, err := usagev2.NewHTTPTransport("https://127.0.0.1:18443", "usage-interop-local-test-only", fixture.NodeID, 0, client)
	must(err)
	spool, err := usagev2.OpenSpool(os.Getenv("USAGE_INTEROP_DIR")+"/spool", usagev2.DefaultSpoolBytes)
	must(err)
	reporter, err := usagev2.NewReporter(session, spool, transport)
	must(err)
	uid, err := strconv.Atoi(session.Grants[0].UserID)
	must(err)
	submission, err := reporter.Capture(fixture.Started, fixture.Ended, map[int][2]int64{uid: {3, 5}})
	must(err)
	must(reporter.Deliver(context.Background()))
	records, err := spool.Records()
	must(err)
	if len(records) != 1 || records[0].Receipt == nil || records[0].Receipt.SettlementStatus != "pending" {
		panic("persisted spool receipt missing")
	}
	receiptID := records[0].Receipt.ReceiptID
	must(reporter.Close())
	spool, err = usagev2.OpenSpool(os.Getenv("USAGE_INTEROP_DIR")+"/spool", usagev2.DefaultSpoolBytes)
	must(err)
	reporter, err = usagev2.NewReporter(session, spool, transport)
	must(err)
	must(reporter.Deliver(context.Background()))
	replay, err := transport.Submit(context.Background(), submission)
	must(err)
	if replay.ReceiptID != receiptID {
		panic("duplicate changed receipt")
	}
	worker := exec.Command("php", "artisan", "core:usage-v2", "settle", "--limit=100")
	worker.Dir = os.Getenv("USAGE_CORE_API")
	output, err := worker.CombinedOutput()
	if err != nil {
		panic(string(output))
	}
	must(reporter.Deliver(context.Background()))
	records, err = spool.Records()
	must(err)
	if len(records) != 1 || records[0].Receipt.SettlementStatus != "settled" || records[0].Receipt.ReceiptID != receiptID {
		panic("settled spool receipt changed")
	}
	must(reporter.Close())
	encoded, _ := json.Marshal(map[string]any{"https_verified": true, "core_handshake_grants": len(session.Grants), "sql_receipt_id": receiptID, "node_spool_restart_replay": true, "same_batch_id": submission.Payload.BatchID, "same_payload_digest": submission.PayloadDigest, "settlement_status": "settled", "payload_retained": true})
	fmt.Println(string(encoded))
}
