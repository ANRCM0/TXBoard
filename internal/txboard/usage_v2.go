package txboard

import (
	"errors"
	"strconv"

	"github.com/ANRCM0/TX-Node/internal/usagev2"
)

// NewUsageV2Reporter is an explicit opt-in for a bounded interval collector.
// The existing Service remains v1; it has no authorized interval boundaries.
// Credentials stay in this transport adapter and never enter the usage spool.
func (c *Client) NewUsageV2Reporter(hs *Handshake, spoolDir, legacyPendingPath string, countersEmpty bool) (*usagev2.Reporter, error) {
	if hs == nil || hs.ProtocolVersion != 1 || len(hs.UsageV2) == 0 {
		return nil, errors.New("Core usage v2 capability is required")
	}
	var session usagev2.Session
	if err := usagev2.StrictDecode(hs.UsageV2, &session, usagev2.MaxPayloadBytes); err != nil {
		return nil, err
	}
	if err := session.Validate(); err != nil {
		return nil, err
	}
	if c.nodeID <= 0 || session.NodeID != strconv.Itoa(c.nodeID) {
		return nil, usagev2.ErrReceiptMismatch
	}
	if err := usagev2.CheckHandoff(legacyPendingPath, countersEmpty); err != nil {
		return nil, err
	}
	transport, err := usagev2.NewHTTPTransport(c.baseURL, c.token, session.NodeID, c.machineID, c.http)
	if err != nil {
		return nil, err
	}
	spool, err := usagev2.OpenSpool(spoolDir, usagev2.DefaultSpoolBytes)
	if err != nil {
		return nil, err
	}
	reporter, err := usagev2.NewReporter(session, spool, transport)
	if err != nil {
		spool.Close()
		return nil, err
	}
	return reporter, nil
}
