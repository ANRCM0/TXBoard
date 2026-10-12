package geoassets

import (
	"errors"
	"os"
	"testing"

	"github.com/ANRCM0/TX-Node/internal/config"
	"github.com/ANRCM0/TX-Node/internal/model"
)

type fakeBackend struct {
	calls        int
	dir          string
	needGeoIP    bool
	needGeoSite  bool
	kernelType   string
	err          error
}

func (f *fakeBackend) Ensure(dir string, needGeoIP, needGeoSite bool, kernelType string) error {
	f.calls++
	f.dir = dir
	f.needGeoIP = needGeoIP
	f.needGeoSite = needGeoSite
	f.kernelType = kernelType
	return f.err
}

func TestPrepareXraySkipsWhenRoutesDoNotNeedGeoAssets(t *testing.T) {
	backend := &fakeBackend{}
	coordinator := NewWithBackend(backend)

	t.Setenv("XRAY_LOCATION_ASSET", "unchanged")
	err := coordinator.PrepareXray(
		config.KernelConfig{GeoDataDir: "/geo"},
		&model.NodeSpec{Routes: []model.RouteRule{{Match: []string{"example.com"}}}},
	)
	if err != nil {
		t.Fatalf("PrepareXray: %v", err)
	}
	if backend.calls != 0 {
		t.Fatalf("backend calls = %d, want 0", backend.calls)
	}
	if got := os.Getenv("XRAY_LOCATION_ASSET"); got != "unchanged" {
		t.Fatalf("asset env unexpectedly changed: %q", got)
	}
}

func TestPrepareXrayDelegatesGeoIPAndSetsCompatibilityEnv(t *testing.T) {
	backend := &fakeBackend{}
	coordinator := NewWithBackend(backend)
	t.Setenv("XRAY_LOCATION_ASSET", "")

	err := coordinator.PrepareXray(
		config.KernelConfig{GeoDataDir: "/var/lib/tx-node/geo"},
		&model.NodeSpec{Routes: []model.RouteRule{{Match: []string{"geoip:cn"}}}},
	)
	if err != nil {
		t.Fatalf("PrepareXray: %v", err)
	}
	if backend.calls != 1 || !backend.needGeoIP || backend.needGeoSite {
		t.Fatalf("unexpected backend request: %#v", backend)
	}
	if backend.dir != "/var/lib/tx-node/geo" || backend.kernelType != "xray" {
		t.Fatalf("unexpected backend target: %#v", backend)
	}
	if got := os.Getenv("XRAY_LOCATION_ASSET"); got != "/var/lib/tx-node/geo" {
		t.Fatalf("XRAY_LOCATION_ASSET = %q", got)
	}
}

func TestPrepareXrayDelegatesGeoSite(t *testing.T) {
	backend := &fakeBackend{}
	coordinator := NewWithBackend(backend)

	err := coordinator.PrepareXray(
		config.KernelConfig{GeoDataDir: "/geo"},
		&model.NodeSpec{Routes: []model.RouteRule{{Match: []string{"geosite:google"}}}},
	)
	if err != nil {
		t.Fatalf("PrepareXray: %v", err)
	}
	if backend.calls != 1 || backend.needGeoIP || !backend.needGeoSite {
		t.Fatalf("unexpected backend request: %#v", backend)
	}
}

func TestPrepareXrayPreservesEnvWhenBackendFails(t *testing.T) {
	backend := &fakeBackend{err: errors.New("download failed")}
	coordinator := NewWithBackend(backend)
	t.Setenv("XRAY_LOCATION_ASSET", "")

	err := coordinator.PrepareXray(
		config.KernelConfig{GeoDataDir: "/geo"},
		&model.NodeSpec{Routes: []model.RouteRule{{Match: []string{"geoip:private"}}}},
	)
	if err == nil || err.Error() != "download failed" {
		t.Fatalf("unexpected error: %v", err)
	}
	if got := os.Getenv("XRAY_LOCATION_ASSET"); got != "/geo" {
		t.Fatalf("compatibility env was not preserved after backend error: %q", got)
	}
}
