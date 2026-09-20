package controlplane

// AuditTarget describes the remote identity required by the embedded access
// audit reporter. It is intentionally protocol-neutral so core service code
// does not need to understand Xboard panel configuration.
type AuditTarget struct {
	BaseURL   string
	Token     string
	NodeID    int
	NodeType  string
	MachineID int
}

// AuditTargetProvider is an optional control-plane capability. Control planes
// that support TX-Node's embedded access-audit reporter can expose the target
// identity through this interface. Local/standalone control planes simply do
// not implement it.
type AuditTargetProvider interface {
	AuditTarget() (AuditTarget, bool)
}

// AuditTargetOf resolves the optional audit capability without coupling callers
// to a concrete control-plane implementation.
func AuditTargetOf(cp ControlPlane) (AuditTarget, bool) {
	provider, ok := cp.(AuditTargetProvider)
	if !ok || provider == nil {
		return AuditTarget{}, false
	}
	return provider.AuditTarget()
}

func auditTargetFromPanelIdentity(url, token string, nodeID int, nodeType string, machineID int) (AuditTarget, bool) {
	if url == "" || token == "" {
		return AuditTarget{}, false
	}
	return AuditTarget{
		BaseURL:   url,
		Token:     token,
		NodeID:    nodeID,
		NodeType:  nodeType,
		MachineID: machineID,
	}, true
}

// AuditTarget exposes the Xboard adapter's audit transport identity.
func (p *PanelControlPlane) AuditTarget() (AuditTarget, bool) {
	if p == nil {
		return AuditTarget{}, false
	}
	return auditTargetFromPanelIdentity(p.cfg.URL, p.cfg.Token, p.cfg.NodeID, p.cfg.NodeType, p.cfg.MachineID)
}

// AuditTarget exposes the machine-mode Xboard adapter's per-node identity.
func (p *MachinePanelControlPlane) AuditTarget() (AuditTarget, bool) {
	if p == nil || p.client == nil {
		return AuditTarget{}, false
	}
	identity := p.client.Identity()
	return auditTargetFromPanelIdentity(identity.URL, identity.Token, identity.NodeID, identity.NodeType, identity.MachineID)
}
