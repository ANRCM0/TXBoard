package certcoord

import (
	"fmt"
	"strings"

	"github.com/ANRCM0/TX-Node/internal/cert/dnsproviders"
	"github.com/ANRCM0/TX-Node/internal/model"
)

// ValidateNodeConfig validates certificate-runtime compatibility declared by a
// ControlPlane node spec.
//
// Provider catalog knowledge intentionally stays behind the certificate
// coordination boundary so the core Service does not depend on the optional
// DNS-provider registry.
func ValidateNodeConfig(spec *model.NodeSpec) error {
	if spec == nil || spec.CertConfig == nil {
		return nil
	}

	mode := strings.ToLower(strings.TrimSpace(spec.CertConfig.CertMode))
	if mode != "dns" {
		return nil
	}

	provider := strings.TrimSpace(spec.CertConfig.DNSProvider)
	if provider == "" {
		return fmt.Errorf("dns cert mode requires cert_config.dns_provider")
	}
	if _, ok := dnsproviders.Get(provider); !ok {
		return fmt.Errorf(
			"unsupported cert_config.dns_provider %q (supported: %s)",
			provider,
			strings.Join(dnsproviders.CanonicalNames(), ", "),
		)
	}
	return nil
}
