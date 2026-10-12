package config

import "testing"

func TestUsageObserverRequiresExplicitInstanceOptIn(t *testing.T) {
	base := `panel:
  url: https://example.test
  token: local-fixture-token
  node_id: 7
`
	cfg, err := Load(writeTemp(t, base))
	if err != nil {
		t.Fatal(err)
	}
	if cfg.UsageObserver.Enabled {
		t.Fatal("observer enabled by default")
	}
	cfg, err = Load(writeTemp(t, base+`usage_observer:
  enabled: true
  directory: /var/lib/txnode-observations
  max_bytes: 1048576
`))
	if err != nil {
		t.Fatal(err)
	}
	if !cfg.UsageObserver.Enabled || cfg.UsageObserver.MaxBytes != 1048576 || cfg.UsageObserver.Directory != "/var/lib/txnode-observations" {
		t.Fatalf("configuration: %+v", cfg.UsageObserver)
	}
	child := Config{}
	child.inheritFrom(cfg)
	if child.UsageObserver.Enabled || child.UsageObserver.Directory != "" {
		t.Fatal("observer opt-in inherited silently")
	}
}
