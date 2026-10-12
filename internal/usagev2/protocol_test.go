package usagev2

import (
	"encoding/json"
	"strings"
	"testing"
)

const goldenPayload = `{"batch_id":"node42_20261012_000001","ended_at":1791770460,"entries":[{"access_subject_id":"9001","auth_snapshot_id":"7001","raw_down_bytes":"2048","raw_up_bytes":"1024","tariff_revision_id":"301","terms_id":"6001","usage_period_id":"8001"}],"node_id":"42","protocol_version":2,"source_epoch":"1","started_at":1791770400}`
const goldenDigest = "sha256:d7a022a63a0937d08be73abb133abf633350e187b128d8dc40485c74619c5eee"

func golden(t *testing.T) Submission {
	t.Helper()
	s, err := ParseSubmission([]byte(`{"payload":` + goldenPayload + `,"payload_digest":"` + goldenDigest + `"}`))
	if err != nil {
		t.Fatal(err)
	}
	return s
}

func session() Session {
	return Session{2, "core_instance_0001", "42", "1", "1", 86400, 43200, 172800, 1791770400, []Grant{{"7", "9001", "7001", "301", "6001", "8001", 1791770400, 1791774000, "0"}}}
}

func receiptFor(s Submission, status string) Receipt {
	r := Receipt{ProtocolVersion: 2, CoreInstanceID: "core_instance_0001", ReceiptGeneration: "1", NodeID: s.Payload.NodeID, SourceEpoch: s.Payload.SourceEpoch, BatchID: s.Payload.BatchID, PayloadDigest: s.PayloadDigest, ReceiptStatus: "persisted", SettlementStatus: status, AcceptedAt: 1791770462, ReceiptID: "receipt_42_000001"}
	if status == "settled" {
		at := int64(1791770463)
		r.SettledAt = &at
	}
	return r
}

func TestCentralGoldenCanonicalDigest(t *testing.T) {
	s := golden(t)
	b, err := s.Payload.Canonical()
	if err != nil || string(b) != goldenPayload {
		t.Fatalf("canonical mismatch: %s %v", b, err)
	}
}

func TestStrictSubmissionRejectsAmbiguousOrOversizedJSON(t *testing.T) {
	valid := `{"payload":` + goldenPayload + `,"payload_digest":"` + goldenDigest + `"}`
	for name, input := range map[string]string{
		"duplicate outer":  strings.Replace(valid, `"payload":`, `"payload":null,"payload":`, 1),
		"duplicate nested": strings.Replace(valid, `"node_id":"42"`, `"node_id":"43","node_id":"42"`, 1),
		"unknown field":    strings.Replace(valid, `"node_id":"42"`, `"secret":"never","node_id":"42"`, 1),
		"number ID":        strings.Replace(valid, `"node_id":"42"`, `"node_id":42`, 1),
		"leading zero":     strings.Replace(valid, `"node_id":"42"`, `"node_id":"042"`, 1),
		"numeric exponent": strings.Replace(valid, `"raw_up_bytes":"1024"`, `"raw_up_bytes":"1e3"`, 1),
		"negative bytes":   strings.Replace(valid, `"raw_up_bytes":"1024"`, `"raw_up_bytes":"-1"`, 1),
		"unsafe time":      strings.Replace(valid, `"ended_at":1791770460`, `"ended_at":9007199254740992`, 1),
		"empty interval":   strings.Replace(valid, `"ended_at":1791770460`, `"ended_at":1791770400`, 1),
		"beyond PiB":       strings.Replace(valid, `"raw_up_bytes":"1024"`, `"raw_up_bytes":"1125899906842625"`, 1),
		"digest conflict":  strings.Replace(valid, goldenDigest, "sha256:"+strings.Repeat("0", 64), 1),
		"trailing JSON":    valid + ` {}`,
		"oversized":        strings.Repeat(" ", MaxPayloadBytes) + valid,
	} {
		t.Run(name, func(t *testing.T) {
			if _, err := ParseSubmission([]byte(input)); err == nil {
				t.Fatal("unsafe submission accepted")
			}
		})
	}
}

func TestBuilderSortsLexicalTupleWithoutAliasingAndRejectsDuplicates(t *testing.T) {
	a := golden(t).Payload.Entries[0]
	a.AccessSubjectID = "9"
	b := a
	b.AccessSubjectID = "90"
	input := []Entry{b, a}
	s, err := NewSubmission("42", "1", 1, 2, input)
	if err != nil {
		t.Fatal(err)
	}
	if s.Payload.Entries[0].AccessSubjectID != "9" || input[0].AccessSubjectID != "90" {
		t.Fatal("tuple lexical prefix ordering or input aliasing wrong")
	}
	if _, err := NewSubmission("42", "1", 1, 2, []Entry{a, a}); err == nil {
		t.Fatal("duplicate tuple accepted")
	}
	if _, err := NewSubmission("42", "1", 1, 2, make([]Entry, MaxEntries+1)); err == nil {
		t.Fatal("entry cap ignored")
	}
}

func TestGrantCollectorUsesOnlyCoreMappingAndBoundedLease(t *testing.T) {
	s := session()
	got, err := s.Build(1791770400, 1791770460, map[int][2]int64{7: {1024, 2048}})
	if err != nil {
		t.Fatal(err)
	}
	if got.Payload.Entries[0].AccessSubjectID != "9001" || got.Payload.Entries[0].RawUpBytes != "1024" {
		t.Fatal("counter mapping or raw bytes changed")
	}
	for name, fn := range map[string]func() error{
		"unknown user":     func() error { _, e := s.Build(1791770400, 1791770460, map[int][2]int64{8: {1, 2}}); return e },
		"before issuance":  func() error { _, e := s.Build(1791770399, 1791770460, map[int][2]int64{7: {1, 2}}); return e },
		"cross expiry":     func() error { _, e := s.Build(1791770400, 1791774001, map[int][2]int64{7: {1, 2}}); return e },
		"negative":         func() error { _, e := s.Build(1791770400, 1791770460, map[int][2]int64{7: {-1, 2}}); return e },
		"ambiguous grants": func() error { copy := s; copy.Grants = append(copy.Grants, copy.Grants[0]); return copy.Validate() },
	} {
		t.Run(name, func(t *testing.T) {
			if fn() == nil {
				t.Fatal("unproven interval or mapping accepted")
			}
		})
	}
}

func TestReceiptRequiresCompleteBoundIdentityAndCommittedState(t *testing.T) {
	s := golden(t)
	valid := receiptFor(s, "settled")
	if err := valid.Validate(session(), s); err != nil {
		t.Fatal(err)
	}
	for name, mutate := range map[string]func(*Receipt){
		"wrong epoch": func(r *Receipt) { r.SourceEpoch = "2" }, "wrong node": func(r *Receipt) { r.NodeID = "43" },
		"wrong batch": func(r *Receipt) { r.BatchID = "other_batch_0001" }, "wrong digest": func(r *Receipt) { r.PayloadDigest = "sha256:" + strings.Repeat("0", 64) },
		"wrong Core": func(r *Receipt) { r.CoreInstanceID = "core_instance_0002" }, "restored generation": func(r *Receipt) { r.ReceiptGeneration = "2" },
		"v1 queued": func(r *Receipt) { r.ReceiptStatus = "queued" }, "settled without time": func(r *Receipt) { r.SettledAt = nil },
	} {
		t.Run(name, func(t *testing.T) {
			r := valid
			mutate(&r)
			if r.Validate(session(), s) == nil {
				t.Fatal("unbound receipt accepted")
			}
		})
	}
}

func TestSessionHasNoCredentialMaterial(t *testing.T) {
	b, err := json.Marshal(session())
	if err != nil {
		t.Fatal(err)
	}
	for _, key := range []string{"token", "password", "uuid", "credential"} {
		if strings.Contains(string(b), key) {
			t.Fatal("usage metadata includes credential field")
		}
	}
}
