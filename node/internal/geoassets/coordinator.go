package geoassets

import (
	"os"

	"github.com/ANRCM0/TX-Node/internal/config"
	"github.com/ANRCM0/TX-Node/internal/kernel"
	"github.com/ANRCM0/TX-Node/internal/kernel/geodata"
	"github.com/ANRCM0/TX-Node/internal/model"
)

// Backend is the existing geo-data acquisition runtime.
//
// The default backend delegates to kernel/geodata and remains authoritative for
// download URLs, filesystem writes and retry/error behavior. Coordinator only
// decides whether Xray needs those optional assets and prepares compatibility
// process state.
type Backend interface {
	Ensure(dir string, needGeoIP, needGeoSite bool, kernelType string) error
}

type defaultBackend struct{}

func (defaultBackend) Ensure(dir string, needGeoIP, needGeoSite bool, kernelType string) error {
	return geodata.Ensure(dir, needGeoIP, needGeoSite, kernelType)
}

// Coordinator isolates optional geo-data acquisition from the Xray kernel
// implementation. Route compilation remains a core data-plane responsibility;
// only asset acquisition/preparation is delegated here.
type Coordinator struct {
	backend Backend
}

func New() *Coordinator {
	return NewWithBackend(defaultBackend{})
}

func NewWithBackend(backend Backend) *Coordinator {
	if backend == nil {
		backend = defaultBackend{}
	}
	return &Coordinator{backend: backend}
}

// PrepareXray preserves the pre-S3 behavior:
//   - assets are requested only when panel route rules reference geoip/geosite;
//   - the existing geodata backend performs acquisition;
//   - XRAY_LOCATION_ASSET is set even when acquisition reports an error, so
//     already-present partial/manual assets remain discoverable.
//
// XRAY_LOCATION_ASSET is process-scoped legacy compatibility state. This
// coordinator makes that side effect explicit but intentionally does not change
// its semantics in S3.
func (c *Coordinator) PrepareXray(cfg config.KernelConfig, spec *model.NodeSpec) error {
	if c == nil || spec == nil {
		return nil
	}

	needIP := kernel.NeedsGeoIP(spec.Routes)
	needSite := kernel.NeedsGeoSite(spec.Routes)
	if !needIP && !needSite {
		return nil
	}

	dir := cfg.GeoDataDir
	err := c.backend.Ensure(dir, needIP, needSite, "xray")

	// Preserve existing compatibility behavior: the Xray runtime resolves geo
	// assets through this process-level environment variable.
	_ = os.Setenv("XRAY_LOCATION_ASSET", dir)
	return err
}
