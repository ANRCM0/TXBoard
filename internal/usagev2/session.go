package usagev2

import (
	"errors"
	"fmt"
	"strconv"
)

type Grant struct {
	UserID            string `json:"user_id"`
	AccessSubjectID   string `json:"access_subject_id"`
	AuthSnapshotID    string `json:"auth_snapshot_id"`
	TariffRevisionID  string `json:"tariff_revision_id"`
	TermsID           string `json:"terms_id"`
	UsagePeriodID     string `json:"usage_period_id"`
	IssuedAt          int64  `json:"issued_at"`
	LeaseExpiresAt    int64  `json:"lease_expires_at"`
	RevocationVersion string `json:"revocation_version"`
}

type Session struct {
	ProtocolVersion         int     `json:"protocol_version"`
	CoreInstanceID          string  `json:"core_instance_id"`
	NodeID                  string  `json:"node_id"`
	SourceEpoch             string  `json:"source_epoch"`
	ReceiptGeneration       string  `json:"receipt_generation"`
	RetentionSeconds        int64   `json:"retention_seconds"`
	MaxLateReportSeconds    int64   `json:"max_late_report_seconds"`
	ReceiptRetentionSeconds int64   `json:"receipt_retention_seconds"`
	IssuedAt                int64   `json:"issued_at"`
	Grants                  []Grant `json:"grants"`
}

func (s Session) Validate() error {
	if s.ProtocolVersion != 2 || !identifier.MatchString(s.CoreInstanceID) {
		return errors.New("missing or invalid usage v2 capability")
	}
	for _, v := range []string{s.NodeID, s.SourceEpoch, s.ReceiptGeneration} {
		if err := Decimal(v, false); err != nil {
			return err
		}
	}
	if s.IssuedAt < 0 || s.IssuedAt > MaxSafeTime || s.RetentionSeconds <= 0 || s.RetentionSeconds > MaxSafeTime || s.MaxLateReportSeconds <= 0 || s.MaxLateReportSeconds > s.RetentionSeconds || s.ReceiptRetentionSeconds < s.RetentionSeconds || s.ReceiptRetentionSeconds > MaxSafeTime {
		return errors.New("invalid negotiated usage recovery retention")
	}
	if s.Grants == nil || len(s.Grants) > MaxEntries {
		return errors.New("usage v2 grants missing or too large")
	}
	users := map[string]bool{}
	subjects := map[string]bool{}
	for _, g := range s.Grants {
		for _, v := range []string{g.UserID, g.AccessSubjectID, g.AuthSnapshotID, g.TariffRevisionID, g.TermsID, g.UsagePeriodID} {
			if err := Decimal(v, false); err != nil {
				return fmt.Errorf("invalid Core usage grant: %w", err)
			}
		}
		if err := Decimal(g.RevocationVersion, true); err != nil {
			return err
		}
		if users[g.UserID] || subjects[g.AccessSubjectID] {
			return errors.New("ambiguous runtime user or subject grant")
		}
		users[g.UserID] = true
		subjects[g.AccessSubjectID] = true
		if g.IssuedAt < 0 || g.IssuedAt >= g.LeaseExpiresAt || g.LeaseExpiresAt > MaxSafeTime {
			return errors.New("invalid usage grant lease")
		}
	}
	return nil
}

// Build requires counters from an explicitly bounded collection interval.
// Calling it with an unbounded v1 tracker flush is unsafe and unsupported.
// The Core-provided mapping is immutable for this interval; expired grants may
// still account for traffic that was collected entirely within their lease.
func (s Session) Build(startedAt, endedAt int64, raw map[int][2]int64) (Submission, error) {
	if err := s.Validate(); err != nil {
		return Submission{}, err
	}
	grants := map[string]Grant{}
	for _, g := range s.Grants {
		grants[g.UserID] = g
	}
	entries := make([]Entry, 0, len(raw))
	for uid, counters := range raw {
		g, ok := grants[strconv.Itoa(uid)]
		if !ok {
			return Submission{}, errors.New("runtime counter has no unique Core usage grant")
		}
		if startedAt < g.IssuedAt || endedAt > g.LeaseExpiresAt {
			return Submission{}, errors.New("collection interval crosses usage grant boundary")
		}
		if counters[0] < 0 || counters[1] < 0 {
			return Submission{}, errors.New("negative raw usage counter")
		}
		entries = append(entries, Entry{g.AccessSubjectID, g.AuthSnapshotID, strconv.FormatInt(counters[1], 10), strconv.FormatInt(counters[0], 10), g.TariffRevisionID, g.TermsID, g.UsagePeriodID})
	}
	return NewSubmission(s.NodeID, s.SourceEpoch, startedAt, endedAt, entries)
}

type Receipt struct {
	ProtocolVersion   int    `json:"protocol_version"`
	CoreInstanceID    string `json:"core_instance_id"`
	ReceiptGeneration string `json:"receipt_generation"`
	NodeID            string `json:"node_id"`
	SourceEpoch       string `json:"source_epoch"`
	BatchID           string `json:"batch_id"`
	PayloadDigest     string `json:"payload_digest"`
	ReceiptStatus     string `json:"receipt_status"`
	SettlementStatus  string `json:"settlement_status"`
	AcceptedAt        int64  `json:"accepted_at"`
	ReceiptID         string `json:"receipt_id"`
	SettledAt         *int64 `json:"settled_at,omitempty"`
	UpdatedAt         *int64 `json:"updated_at,omitempty"`
	ReasonCode        string `json:"reason_code,omitempty"`
}

func (r Receipt) Validate(s Session, submission Submission) error {
	p := submission.Payload
	if r.ProtocolVersion != 2 || r.CoreInstanceID != s.CoreInstanceID || r.NodeID != p.NodeID || r.SourceEpoch != p.SourceEpoch || r.BatchID != p.BatchID || r.PayloadDigest != submission.PayloadDigest {
		return ErrReceiptMismatch
	}
	if r.ReceiptGeneration != s.ReceiptGeneration {
		return ErrRecoveryRequired
	}
	if r.ReceiptStatus != "persisted" || !identifier.MatchString(r.ReceiptID) || r.AcceptedAt < 0 || r.AcceptedAt > MaxSafeTime {
		return errors.New("usage v2 durable receipt missing")
	}
	switch r.SettlementStatus {
	case "pending", "processing", "retry_wait", "quarantined":
		if r.SettledAt != nil {
			return errors.New("unsettled receipt has settlement timestamp")
		}
	case "settled":
		if r.SettledAt == nil || *r.SettledAt < r.AcceptedAt || *r.SettledAt > MaxSafeTime {
			return errors.New("settled receipt lacks valid committed timestamp")
		}
	default:
		return errors.New("unknown usage settlement state")
	}
	if r.UpdatedAt != nil && (*r.UpdatedAt < r.AcceptedAt || *r.UpdatedAt > MaxSafeTime) {
		return errors.New("invalid receipt update time")
	}
	if r.ReasonCode != "" {
		for _, b := range []byte(r.ReasonCode) {
			if (b < 'A' || b > 'Z') && (b < '0' || b > '9') && b != '_' {
				return errors.New("invalid receipt reason code")
			}
		}
		if len(r.ReasonCode) > 80 {
			return errors.New("receipt reason code too long")
		}
	}
	return nil
}
