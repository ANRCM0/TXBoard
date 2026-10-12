// Package usagev2 implements the bounded, non-secret Node Usage v2 schema.
// It does not infer billing identities from runtime users or manage credentials.
package usagev2

import (
	"bytes"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"reflect"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

const (
	MaxPayloadBytes        = 1 << 20
	MaxEntries             = 10000
	MaxSafeTime     int64  = 1<<53 - 1
	MaxRawBytes     uint64 = 1 << 50
)

var (
	ErrPayloadConflict  = errors.New("usage v2 batch payload conflict")
	ErrReceiptMismatch  = errors.New("usage v2 receipt identity or digest mismatch")
	ErrRecoveryRequired = errors.New("usage v2 receipt generation changed or confirmed receipt missing; audited recovery required")
	identifier          = regexp.MustCompile(`^[A-Za-z0-9:_-]{8,80}$`)
	digestPattern       = regexp.MustCompile(`^sha256:[0-9a-f]{64}$`)
)

// Field order is deliberately the ASCII-key order of this restricted schema.
// All strings are validated ASCII, all numeric values are safe integers. The
// emitted bytes match JCS for this schema; this is not a generic JCS encoder.
type Entry struct {
	AccessSubjectID  string `json:"access_subject_id"`
	AuthSnapshotID   string `json:"auth_snapshot_id"`
	RawDownBytes     string `json:"raw_down_bytes"`
	RawUpBytes       string `json:"raw_up_bytes"`
	TariffRevisionID string `json:"tariff_revision_id"`
	TermsID          string `json:"terms_id"`
	UsagePeriodID    string `json:"usage_period_id"`
}

type Payload struct {
	BatchID         string  `json:"batch_id"`
	EndedAt         int64   `json:"ended_at"`
	Entries         []Entry `json:"entries"`
	NodeID          string  `json:"node_id"`
	ProtocolVersion int     `json:"protocol_version"`
	SourceEpoch     string  `json:"source_epoch"`
	StartedAt       int64   `json:"started_at"`
}

type Submission struct {
	Payload       Payload `json:"payload"`
	PayloadDigest string  `json:"payload_digest"`
}

type Query struct {
	ProtocolVersion int    `json:"protocol_version"`
	NodeID          string `json:"node_id"`
	SourceEpoch     string `json:"source_epoch"`
	BatchID         string `json:"batch_id"`
	PayloadDigest   string `json:"payload_digest"`
}

func Decimal(value string, allowZero bool) error {
	if value == "" || (len(value) > 1 && value[0] == '0') || (!allowZero && value == "0") {
		return errors.New("noncanonical decimal string")
	}
	for _, c := range []byte(value) {
		if c < '0' || c > '9' {
			return errors.New("noncanonical decimal string")
		}
	}
	if len(value) > 20 {
		return errors.New("decimal exceeds uint64")
	}
	if _, err := strconv.ParseUint(value, 10, 64); err != nil {
		return errors.New("decimal exceeds uint64")
	}
	return nil
}

func entryKey(e Entry) string {
	return e.AccessSubjectID + "\x00" + e.AuthSnapshotID + "\x00" + e.TariffRevisionID + "\x00" + e.UsagePeriodID
}

func (p Payload) Validate() error {
	if p.ProtocolVersion != 2 || !identifier.MatchString(p.BatchID) {
		return errors.New("invalid usage v2 version or batch ID")
	}
	if err := Decimal(p.NodeID, false); err != nil {
		return fmt.Errorf("node_id: %w", err)
	}
	if err := Decimal(p.SourceEpoch, false); err != nil {
		return fmt.Errorf("source_epoch: %w", err)
	}
	if p.StartedAt < 0 || p.EndedAt > MaxSafeTime || p.StartedAt >= p.EndedAt {
		return errors.New("invalid half-open usage interval")
	}
	if len(p.Entries) == 0 || len(p.Entries) > MaxEntries {
		return errors.New("usage entry count outside limits")
	}
	previous := ""
	for _, e := range p.Entries {
		for _, id := range []string{e.AccessSubjectID, e.AuthSnapshotID, e.TariffRevisionID, e.TermsID, e.UsagePeriodID} {
			if err := Decimal(id, false); err != nil {
				return fmt.Errorf("entry identity: %w", err)
			}
		}
		for _, v := range []string{e.RawUpBytes, e.RawDownBytes} {
			if err := Decimal(v, true); err != nil {
				return fmt.Errorf("entry bytes: %w", err)
			}
			n, _ := strconv.ParseUint(v, 10, 64)
			if n > MaxRawBytes {
				return errors.New("entry raw bytes exceed 1 PiB")
			}
		}
		key := entryKey(e)
		if previous != "" && key <= previous {
			return errors.New("entries must be sorted and unique")
		}
		previous = key
	}
	return nil
}

func (p Payload) Canonical() ([]byte, error) {
	if err := p.Validate(); err != nil {
		return nil, err
	}
	b, err := json.Marshal(p)
	if err != nil {
		return nil, err
	}
	if len(b) > MaxPayloadBytes {
		return nil, errors.New("usage payload exceeds 1 MiB")
	}
	return b, nil
}

func (s Submission) Validate() error {
	b, err := s.Payload.Canonical()
	if err != nil {
		return err
	}
	hash := sha256.Sum256(b)
	if !digestPattern.MatchString(s.PayloadDigest) || s.PayloadDigest != "sha256:"+hex.EncodeToString(hash[:]) {
		return ErrPayloadConflict
	}
	encoded, err := json.Marshal(s)
	if err != nil {
		return err
	}
	if len(encoded) > MaxPayloadBytes {
		return errors.New("usage submission exceeds 1 MiB")
	}
	return nil
}

func NewSubmission(nodeID, epoch string, startedAt, endedAt int64, entries []Entry) (Submission, error) {
	nonce := make([]byte, 16)
	if _, err := rand.Read(nonce); err != nil {
		return Submission{}, fmt.Errorf("usage batch entropy unavailable: %w", err)
	}
	copyEntries := append([]Entry(nil), entries...)
	sort.Slice(copyEntries, func(i, j int) bool { return entryKey(copyEntries[i]) < entryKey(copyEntries[j]) })
	s := Submission{Payload: Payload{BatchID: hex.EncodeToString(nonce), EndedAt: endedAt, Entries: copyEntries, NodeID: nodeID, ProtocolVersion: 2, SourceEpoch: epoch, StartedAt: startedAt}}
	b, err := s.Payload.Canonical()
	if err != nil {
		return Submission{}, err
	}
	hash := sha256.Sum256(b)
	s.PayloadDigest = "sha256:" + hex.EncodeToString(hash[:])
	return s, s.Validate()
}

func (s Submission) Query() Query {
	p := s.Payload
	return Query{2, p.NodeID, p.SourceEpoch, p.BatchID, s.PayloadDigest}
}

// StrictDecode bounds bytes before parsing and rejects duplicate JSON keys,
// trailing documents, and fields outside the destination schema.
func StrictDecode(data []byte, dest any, limit int) error {
	if len(data) == 0 || len(data) > limit {
		return errors.New("usage v2 JSON outside byte limit")
	}
	probe := json.NewDecoder(bytes.NewReader(data))
	probe.UseNumber()
	if err := uniqueValue(probe, 0); err != nil {
		return err
	}
	if _, err := probe.Token(); err != io.EOF {
		return errors.New("trailing usage v2 JSON")
	}
	if err := requiredFields(data, reflect.TypeOf(dest)); err != nil {
		return err
	}
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(dest); err != nil {
		return fmt.Errorf("invalid usage v2 JSON: %w", err)
	}
	return nil
}

// Presence matters for valid zero timestamps and byte counts: absent or null
// required fields must not be silently converted to Go zero values.
func requiredFields(data []byte, typ reflect.Type) error {
	for typ.Kind() == reflect.Pointer {
		typ = typ.Elem()
	}
	if typ == reflect.TypeOf(json.RawMessage{}) {
		return nil
	}
	switch typ.Kind() {
	case reflect.Struct:
		var fields map[string]json.RawMessage
		if err := json.Unmarshal(data, &fields); err != nil {
			return err
		}
		for i := 0; i < typ.NumField(); i++ {
			field := typ.Field(i)
			tag := field.Tag.Get("json")
			if tag == "-" || field.PkgPath != "" {
				continue
			}
			name := strings.Split(tag, ",")[0]
			if name == "" {
				name = field.Name
			}
			value, exists := fields[name]
			optional := strings.Contains(tag, ",omitempty")
			if !exists || string(value) == "null" {
				if !optional {
					return fmt.Errorf("usage v2 required field %s missing", name)
				}
				continue
			}
			if err := requiredFields(value, field.Type); err != nil {
				return err
			}
		}
	case reflect.Slice, reflect.Array:
		var values []json.RawMessage
		if err := json.Unmarshal(data, &values); err != nil {
			return err
		}
		for _, value := range values {
			if err := requiredFields(value, typ.Elem()); err != nil {
				return err
			}
		}
	}
	return nil
}

func uniqueValue(d *json.Decoder, depth int) error {
	if depth > 16 {
		return errors.New("usage JSON nesting limit")
	}
	t, err := d.Token()
	if err != nil {
		return err
	}
	delim, ok := t.(json.Delim)
	if !ok {
		return nil
	}
	switch delim {
	case '{':
		seen := map[string]bool{}
		for d.More() {
			key, err := d.Token()
			if err != nil {
				return err
			}
			s, ok := key.(string)
			if !ok || seen[s] {
				return errors.New("duplicate or invalid JSON key")
			}
			seen[s] = true
			if err := uniqueValue(d, depth+1); err != nil {
				return err
			}
		}
	case '[':
		for d.More() {
			if err := uniqueValue(d, depth+1); err != nil {
				return err
			}
		}
	default:
		return errors.New("unexpected JSON delimiter")
	}
	_, err = d.Token()
	return err
}

func ParseSubmission(data []byte) (Submission, error) {
	var s Submission
	if err := StrictDecode(data, &s, MaxPayloadBytes); err != nil {
		return s, err
	}
	return s, s.Validate()
}
