package xray

import (
	"errors"
	"testing"

	"github.com/ANRCM0/TX-Node/internal/config"
	"github.com/ANRCM0/TX-Node/internal/geoassets"
	"github.com/ANRCM0/TX-Node/internal/model"
)

type xrayGeoBackend struct {
	calls       int
	dir         string
	needGeoIP   bool
	needGeoSite bool
	kernelType  string
	err         error
}

func (b *xrayGeoBackend) Ensure(dir string, needGeoIP, needGeoSite bool, kernelType string) error {
	b.calls++
	b.dir = dir
	b.needGeoIP = needGeoIP
	b.needGeoSite = needGeoSite
	b.kernelType = kernelType
	return b.err
}

func TestPrepareGeoAssetsDelegatesWithoutOwningDownloader(t *testing.T) {
	backend := &xrayGeoBackend{}
	x := New(config.KernelConfig{Type: "xray", GeoDataDir: "/geo"})
	x.geoAssets = geoassets.NewWithBackend(backend)

	x.prepareGeoAssets(&model.NodeSpec{
		Routes: []model.RouteRule{{Match: []string{"geoip:cn"}}},
	})

	if backend.calls != 1 || backend.dir != "/geo" || !backend.needGeoIP || backend.needGeoSite || backend.kernelType != "xray" {
		t.Fatalf("unexpected geo asset delegation: %#v", backend)
	}
}

func TestPrepareGeoAssetsKeepsKernelStartNonFatalOnAcquisitionFailure(t *testing.T) {
	backend := &xrayGeoBackend{err: errors.New("download failed")}
	x := New(config.KernelConfig{Type: "xray", GeoDataDir: "/geo"})
	x.geoAssets = geoassets.NewWithBackend(backend)

	// Existing behavior is best-effort: Xray logs the error and continues
	// toward config parsing/start so pre-provisioned assets can still work.
	x.prepareGeoAssets(&model.NodeSpec{
		Routes: []model.RouteRule{{Match: []string{"geosite:google"}}},
	})

	if backend.calls != 1 || !backend.needGeoSite {
		t.Fatalf("unexpected geo asset delegation: %#v", backend)
	}
}
