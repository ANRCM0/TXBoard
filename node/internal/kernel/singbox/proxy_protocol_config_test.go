package singbox

import (
	"testing"

	"github.com/ANRCM0/TX-Node/internal/model"
)

func TestApplyProxyProtocolDisabledByDefault(t *testing.T) {
	base := M{}
	applyProxyProtocol(base, &model.NodeSpec{})
	if _, ok := base["proxy_protocol"]; ok {
		t.Fatal("proxy protocol should stay disabled by default")
	}
}

func TestApplyProxyProtocolRequiresTrustedCIDRs(t *testing.T) {
	base := M{}
	applyProxyProtocol(base, &model.NodeSpec{
		AcceptProxyProtocol: true,
	})
	if _, ok := base["proxy_protocol"]; ok {
		t.Fatal("proxy protocol must not be enabled without trusted upstreams")
	}
}

func TestApplyProxyProtocolAddsTrustedSettings(t *testing.T) {
	base := M{}
	applyProxyProtocol(base, &model.NodeSpec{
		AcceptProxyProtocol: true,
		NetworkSettings: map[string]any{
			"proxyProtocolTrustedCIDRs":   []any{"203.0.113.10", "2001:db8::/32"},
			"proxyProtocolAcceptNoHeader": true,
		},
	})

	if enabled, _ := base["proxy_protocol"].(bool); !enabled {
		t.Fatal("proxy protocol should be enabled")
	}
	trusted, ok := base["proxy_protocol_trusted_cidrs"].([]string)
	if !ok || len(trusted) != 2 || trusted[0] != "203.0.113.10" || trusted[1] != "2001:db8::/32" {
		t.Fatalf("unexpected trusted cidrs: %#v", base["proxy_protocol_trusted_cidrs"])
	}
	if acceptNoHeader, _ := base["proxy_protocol_accept_no_header"].(bool); !acceptNoHeader {
		t.Fatal("proxy protocol accept-no-header should be enabled")
	}
}
