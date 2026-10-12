package certcoord

import (
	"context"

	certruntime "github.com/ANRCM0/TX-Node/internal/cert"
	"github.com/ANRCM0/TX-Node/internal/config"
	"github.com/ANRCM0/TX-Node/internal/kernel"
	"github.com/ANRCM0/TX-Node/internal/model"
)

// Backend is the narrow certificate-runtime contract required by Service.
// cert.Manager remains the authoritative certificate implementation.
type Backend interface {
	Start(context.Context) error
	Stop()
	Reconfigure(context.Context, config.CertConfig) (bool, error)
	TLSCert() kernel.TLSCert
	HasCert() bool
	CertRenewed() bool
}

type ApplyResult struct {
	Config       config.CertConfig
	Changed      bool
	Reconfigured bool
}

// Coordinator adapts Service/ControlPlane certificate intent to the existing
// cert.Manager runtime. It does not implement ACME, DNS providers, persistence,
// certificate parsing or renewal itself.
type Coordinator struct {
	backend Backend
}

func New(cfg config.CertConfig) *Coordinator {
	return NewWithBackend(certruntime.NewManager(cfg))
}

func NewWithBackend(backend Backend) *Coordinator {
	return &Coordinator{backend: backend}
}

func (c *Coordinator) Start(ctx context.Context) error {
	if c == nil || c.backend == nil {
		return nil
	}
	return c.backend.Start(ctx)
}

func (c *Coordinator) Stop() {
	if c == nil || c.backend == nil {
		return
	}
	c.backend.Stop()
}

func (c *Coordinator) TLSCert() kernel.TLSCert {
	if c == nil || c.backend == nil {
		return kernel.TLSCert{}
	}
	return c.backend.TLSCert()
}

func (c *Coordinator) HasCert() bool {
	return c != nil && c.backend != nil && c.backend.HasCert()
}

// ConsumeRenewal preserves cert.Manager's one-shot renewal flag semantics.
func (c *Coordinator) ConsumeRenewal() bool {
	return c != nil && c.backend != nil && c.backend.CertRenewed()
}

// ApplyNodeConfig preserves the existing panel-first certificate behavior.
//
// Modern cert_config is delegated to cert.Manager.Reconfigure and only becomes
// the Service's current config after that runtime accepts it.
//
// Legacy auto_tls/domain fields remain compatibility-only config updates. They
// intentionally do not trigger a runtime reconfigure here because the previous
// Service implementation did not do so either.
func (c *Coordinator) ApplyNodeConfig(
	ctx context.Context,
	current config.CertConfig,
	node *model.NodeSpec,
) (ApplyResult, error) {
	result := ApplyResult{Config: current}
	if c == nil || node == nil {
		return result, nil
	}

	if node.CertConfig != nil {
		next := *node.CertConfig
		next.CertDir = current.CertDir

		if c.backend == nil {
			return result, nil
		}
		changed, err := c.backend.Reconfigure(ctx, next)
		if err != nil {
			return result, err
		}
		return ApplyResult{
			Config:       next,
			Changed:      changed,
			Reconfigured: true,
		}, nil
	}

	next := current
	if node.AutoTLS != current.AutoTLS {
		next.AutoTLS = node.AutoTLS
	}
	if node.Domain != "" && node.Domain != current.Domain {
		next.Domain = node.Domain
	}
	result.Config = next
	return result, nil
}
