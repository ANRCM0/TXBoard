package main

import (
	"bytes"
	"context"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/netip"
	"os"
	"path/filepath"
	"strconv"
	"time"

	"github.com/ANRCM0/TX-Node/internal/kernel/singbox"
	"github.com/ANRCM0/TX-Node/internal/usageinterval"
	"github.com/ANRCM0/TX-Node/internal/usagev2"
	"github.com/sagernet/sing-box/adapter"
	"github.com/sagernet/sing/common/buf"
	"github.com/sagernet/sing/common/bufio"
	singM "github.com/sagernet/sing/common/metadata"
)

const token = "bounded-usage-local-test-only"

type fixture struct {
	NodeID         string `json:"node_id"`
	PeriodID       string `json:"period_id"`
	SubscriptionID string `json:"subscription_id"`
	SubjectID      string `json:"subject_id"`
	GrantID        string `json:"grant_id"`
	TariffID       string `json:"tariff_id"`
	Numerator      string `json:"tariff_numerator"`
	Denominator    string `json:"tariff_denominator"`
}
type capture struct {
	Session    usagev2.Session    `json:"session"`
	Submission usagev2.Submission `json:"submission"`
	Pending    usagev2.Receipt    `json:"pending_receipt"`
	LostACK    bool               `json:"persisted_ack_response_loss"`
	Restart    bool               `json:"node_spool_restart_replay"`
	Conflict   bool               `json:"changed_payload_409"`
	NodeScope  bool               `json:"wrong_node_scope_refused"`
	BadBearer  bool               `json:"wrong_bearer_refused"`
}

func must(err error) {
	if err != nil {
		panic("BOUNDED_USAGE_NODE_FIXTURE_FAILED")
	}
}
func expect(ok bool) {
	if !ok {
		panic("BOUNDED_USAGE_NODE_ASSERTION_FAILED")
	}
}
func writeJSON(path string, value any) {
	encoded, err := json.Marshal(value)
	must(err)
	must(os.WriteFile(path, encoded, 0600))
}
func loadJSON(path string, out any) {
	raw, err := os.ReadFile(path)
	must(err)
	must(json.Unmarshal(raw, out))
}

type lossTransport struct {
	base http.RoundTripper
	lost bool
}

func (t *lossTransport) RoundTrip(r *http.Request) (*http.Response, error) {
	response, err := t.base.RoundTrip(r)
	if err != nil {
		return nil, err
	}
	if !t.lost && r.URL.Path == usagev2.HTTPPrefix+"/submit" && response.StatusCode == 200 {
		_, err := io.Copy(io.Discard, response.Body)
		response.Body.Close()
		if err != nil {
			return nil, err
		}
		t.lost = true
		return nil, errors.New("synthetic loss after real Core persisted ACK")
	}
	return response, nil
}

func main() {
	dir := os.Getenv("BOUNDED_USAGE_DIR")
	endpoint := os.Getenv("BOUNDED_USAGE_ENDPOINT")
	expect(dir != "" && endpoint != "")
	pem, err := os.ReadFile(filepath.Join(dir, "cert.pem"))
	must(err)
	roots := x509.NewCertPool()
	expect(roots.AppendCertsFromPEM(pem))
	base := &http.Transport{TLSClientConfig: &tls.Config{RootCAs: roots, MinVersion: tls.VersionTLS13}, Proxy: nil}
	defer base.CloseIdleConnections()
	client := &http.Client{Transport: base, Timeout: 5 * time.Second}
	var f fixture
	loadJSON(filepath.Join(dir, "fixture.json"), &f)
	phase := os.Args[1]
	if phase == "capture" {
		request, err := http.NewRequest("POST", endpoint+"/txapi/node/v1/handshake", bytes.NewBufferString("{}"))
		must(err)
		request.Header.Set("Authorization", "Bearer "+token)
		request.Header.Set("X-TX-Node-ID", f.NodeID)
		request.Header.Set("Content-Type", "application/json")
		response, err := client.Do(request)
		must(err)
		expect(response.StatusCode == 200 && response.TLS != nil && response.TLS.Version == tls.VersionTLS13)
		raw, err := io.ReadAll(io.LimitReader(response.Body, usagev2.MaxPayloadBytes+1))
		response.Body.Close()
		must(err)
		var envelope struct {
			Data struct {
				Usage json.RawMessage `json:"usage_v2"`
			} `json:"data"`
		}
		must(json.Unmarshal(raw, &envelope))
		var session usagev2.Session
		must(usagev2.StrictDecode(envelope.Data.Usage, &session, usagev2.MaxPayloadBytes))
		must(session.Validate())
		expect(len(session.Grants) == 1 && session.Grants[0].AccessSubjectID == f.SubjectID && session.Grants[0].AuthSnapshotID == f.GrantID && session.Grants[0].TariffRevisionID == f.TariffID && session.Grants[0].UsagePeriodID == f.PeriodID)
		spool, err := usagev2.OpenSpool(filepath.Join(dir, "spool"), 0)
		must(err)
		journal, err := usageinterval.OpenJournal(filepath.Join(dir, "journal"), session, spool)
		must(err)
		uid, err := strconv.Atoi(session.Grants[0].UserID)
		must(err)
		tracker, err := singbox.NewConnTrackerWithBoundedUsage(session, map[string]int{"synthetic-runtime-secret-label": uid}, journal)
		must(err)
		local, peer := net.Pipe()
		defer local.Close()
		defer peer.Close()
		must(local.SetDeadline(time.Now().Add(5 * time.Second)))
		must(peer.SetDeadline(time.Now().Add(5 * time.Second)))
		connection := tracker.RoutedConnection(context.Background(), local, adapter.InboundContext{User: "synthetic-runtime-secret-label", Source: singM.Socksaddr{Addr: netip.MustParseAddr("127.0.0.1")}}, nil, nil)
		done := make(chan error, 1)
		go func() { _, err := peer.Write([]byte("upload-11!!")); done <- err }()
		_, err = io.ReadFull(connection, make([]byte, 11))
		must(err)
		must(<-done)
		go func() { _, err := io.ReadFull(peer, make([]byte, 7)); done <- err }()
		must(bufio.NewExtendedWriter(connection).WriteBuffer(buf.As([]byte("down-07"))))
		must(<-done)
		var submission *usagev2.Submission
		deadline := time.Now().Add(3 * time.Second)
		for time.Now().Before(deadline) {
			submission, err = tracker.FlushBoundedUsage()
			if err == nil {
				break
			}
			expect(err == usageinterval.ErrPending)
			timer := time.NewTimer(20 * time.Millisecond)
			<-timer.C
		}
		must(err)
		expect(submission != nil && submission.Payload.Entries[0].RawUpBytes == "11" && submission.Payload.Entries[0].RawDownBytes == "7")
		_, _, _ = tracker.GetUserTraffic() // Original v1 collector remains available; no second authoritative billing path.
		loss := &lossTransport{base: base}
		lossClient := *client
		lossClient.Transport = loss
		transport, err := usagev2.NewHTTPTransport(endpoint, token, f.NodeID, 0, &lossClient)
		must(err)
		reporter, err := usagev2.NewReporter(session, spool, transport)
		must(err)
		expect(reporter.Deliver(context.Background()) != nil && loss.lost)
		must(reporter.Deliver(context.Background()))
		records, err := spool.Records()
		must(err)
		expect(len(records) == 1 && records[0].Receipt != nil && records[0].Receipt.SettlementStatus == "pending")
		pending := *records[0].Receipt
		duplicate, err := transport.Submit(context.Background(), *submission)
		must(err)
		expect(duplicate.ReceiptID == pending.ReceiptID && duplicate.PayloadDigest == pending.PayloadDigest)
		changed := *submission
		changed.Payload.Entries = append([]usagev2.Entry{}, submission.Payload.Entries...)
		changed.Payload.Entries[0].RawUpBytes = "12"
		canonical, err := changed.Payload.Canonical()
		must(err)
		hash := sha256.Sum256(canonical)
		changed.PayloadDigest = "sha256:" + hex.EncodeToString(hash[:])
		_, err = transport.Submit(context.Background(), changed)
		var conflict *usagev2.HTTPError
		expect(errors.As(err, &conflict) && conflict.Status == 409 && conflict.Code == "BATCH_PAYLOAD_CONFLICT")
		bad, err := usagev2.NewHTTPTransport(endpoint, "synthetic-invalid-bearer", f.NodeID, 0, client)
		must(err)
		_, _, err = bad.Get(context.Background(), submission.Query())
		expect(err != nil)
		other, err := usagev2.NewHTTPTransport(endpoint, token, "2147483647", 0, client)
		must(err)
		query := submission.Query()
		query.NodeID = "2147483647"
		_, _, err = other.Get(context.Background(), query)
		expect(err != nil)
		writeJSON(filepath.Join(dir, "node-capture.json"), capture{session, *submission, pending, true, true, true, true, true})
		tracker.CloseBoundedUsage()
		connection.Close()
		must(journal.Close())
		must(reporter.Close())
		closed, err := spool.Records()
		must(err)
		expect(len(closed) == 1 && closed[0].Receipt != nil && closed[0].Receipt.ReceiptID == pending.ReceiptID)
		spool, err = usagev2.OpenSpool(filepath.Join(dir, "spool"), 0)
		must(err)
		journal, err = usageinterval.OpenJournal(filepath.Join(dir, "journal"), session, spool)
		must(err)
		must(journal.Recover())
		reporter, err = usagev2.NewReporter(session, spool, transport)
		must(err)
		must(reporter.Deliver(context.Background()))
		must(journal.Close())
		must(reporter.Close())
		fmt.Println("BOUNDED_USAGE_CAPTURE_PERSISTED")
	} else if phase == "settled" {
		var original capture
		loadJSON(filepath.Join(dir, "node-capture.json"), &original)
		spool, err := usagev2.OpenSpool(filepath.Join(dir, "spool"), 0)
		must(err)
		journal, err := usageinterval.OpenJournal(filepath.Join(dir, "journal"), original.Session, spool)
		must(err)
		transport, err := usagev2.NewHTTPTransport(endpoint, token, f.NodeID, 0, client)
		must(err)
		reporter, err := usagev2.NewReporter(original.Session, spool, transport)
		must(err)
		must(reporter.Deliver(context.Background()))
		records, err := spool.Records()
		must(err)
		expect(len(records) == 1 && records[0].Receipt != nil && records[0].Receipt.SettlementStatus == "settled" && records[0].Receipt.ReceiptID == original.Pending.ReceiptID && records[0].Submission.PayloadDigest == original.Submission.PayloadDigest)
		settled := *records[0].Receipt
		replay, err := transport.Submit(context.Background(), original.Submission)
		must(err)
		expect(replay.SettlementStatus == "settled" && replay.ReceiptID == settled.ReceiptID)
		must(reporter.Deliver(context.Background()))
		must(journal.Recover())
		must(journal.Close())
		must(reporter.Close())
		final, err := spool.Records()
		must(err)
		expect(len(final) == 1 && final[0].Receipt != nil && final[0].Receipt.SettlementStatus == "settled")
		writeJSON(filepath.Join(dir, "node-settled.json"), map[string]any{"session": original.Session, "submission": original.Submission, "pending_receipt": original.Pending, "settled_receipt": settled, "https_tls13_verified": true, "persisted_response_loss_recovered_by_query": true, "duplicate_original_batch_id": true, "changed_payload_409": true, "spool_and_journal_restart": true, "receipt_retained_after_close": true, "payload_retained": true, "production_authorization": false})
		fmt.Println("BOUNDED_USAGE_SETTLED_RECEIPT_RETAINED")
	} else {
		panic("BOUNDED_USAGE_ACTION_INVALID")
	}
}
