package buildinfo

// These values are injected at build time. Defaults keep local/test builds
// deterministic and make runtime status reporting safe when ldflags are absent.
var (
	Version   = "dev"
	BuildTime = "unknown"
	Commit    = "unknown"
)
