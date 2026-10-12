package panel

import "github.com/ANRCM0/TX-Node/internal/config"

// Identity returns a read-only snapshot of the Xboard connection identity used
// by this client. It exists so protocol adapters can expose optional
// capabilities without leaking Client internals into core service code.
func (c *Client) Identity() config.PanelConfig {
	if c == nil {
		return config.PanelConfig{}
	}
	return config.PanelConfig{
		URL:       c.baseURL,
		Token:     c.token,
		NodeID:    c.nodeID,
		NodeType:  c.nodeType,
		MachineID: c.machineID,
	}
}
