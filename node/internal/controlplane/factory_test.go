package controlplane

import (
	"testing"

	"github.com/PaiMonCai/TXBoard/node/internal/config"
)

func TestProviderForConfig(t *testing.T) {
	if got := ProviderForConfig(&config.Config{}); got != ProviderXboard {
		t.Fatalf("default provider = %q, want %q", got, ProviderXboard)
	}
	if got := ProviderForConfig(&config.Config{Standalone: &config.StandaloneConfig{Enabled: true}}); got != ProviderLocal {
		t.Fatalf("standalone provider = %q, want %q", got, ProviderLocal)
	}
}

func TestNewForConfig(t *testing.T) {
	local := NewForConfig(&config.Config{Standalone: &config.StandaloneConfig{Enabled: true}})
	if _, ok := local.(*LocalControlPlane); !ok {
		t.Fatalf("standalone factory returned %T, want *LocalControlPlane", local)
	}

	remote := NewForConfig(&config.Config{})
	if _, ok := remote.(*XboardControlPlane); !ok {
		t.Fatalf("remote factory returned %T, want *XboardControlPlane", remote)
	}
}
