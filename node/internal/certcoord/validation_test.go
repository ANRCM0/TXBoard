package certcoord

import (
	"strings"
	"testing"

	"github.com/ANRCM0/TX-Node/internal/config"
	"github.com/ANRCM0/TX-Node/internal/model"
)

func TestValidateNodeConfigIgnoresNonDNSModes(t *testing.T) {
	for _, mode := range []string{"", "none", "self", "file", "content", "http"} {
		if err := ValidateNodeConfig(&model.NodeSpec{
			CertConfig: &config.CertConfig{
				CertMode:    mode,
				DNSProvider: "not-a-provider",
			},
		}); err != nil {
			t.Fatalf("mode %q unexpectedly validated DNS provider: %v", mode, err)
		}
	}
}

func TestValidateNodeConfigRequiresDNSProvider(t *testing.T) {
	err := ValidateNodeConfig(&model.NodeSpec{
		CertConfig: &config.CertConfig{CertMode: "dns"},
	})
	if err == nil || err.Error() != "dns cert mode requires cert_config.dns_provider" {
		t.Fatalf("unexpected error: %v", err)
	}
}

func TestValidateNodeConfigAcceptsCanonicalProviderAndAlias(t *testing.T) {
	for _, provider := range []string{"cloudflare", "CF", "alidns", "aliyun"} {
		err := ValidateNodeConfig(&model.NodeSpec{
			CertConfig: &config.CertConfig{
				CertMode:    "dns",
				DNSProvider: provider,
			},
		})
		if err != nil {
			t.Fatalf("provider %q rejected: %v", provider, err)
		}
	}
}

func TestValidateNodeConfigRejectsUnknownProviderWithoutSecrets(t *testing.T) {
	err := ValidateNodeConfig(&model.NodeSpec{
		CertConfig: &config.CertConfig{
			CertMode:    "dns",
			DNSProvider: "unknown-provider",
			DNSEnv: map[string]string{
				"API_TOKEN": "must-not-appear",
			},
		},
	})
	if err == nil {
		t.Fatal("expected unknown provider error")
	}
	if !strings.Contains(err.Error(), "unsupported cert_config.dns_provider") {
		t.Fatalf("unexpected error: %v", err)
	}
	if strings.Contains(err.Error(), "must-not-appear") || strings.Contains(err.Error(), "API_TOKEN") {
		t.Fatalf("validation error leaked DNS credentials: %v", err)
	}
}
