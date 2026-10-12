package config

import "testing"

func TestLocalEventStoreExplicitOptInAndExpandedCoverage(t *testing.T) {
	base := "panel:\n  url: https://example.test\n  token: fixture-not-evidence\n  node_id: 7\n"
	ordinary, err := Load(writeTemp(t, base))
	if err != nil || ordinary.UsageEventStore.Enabled {
		t.Fatalf("default configuration: %+v %v", ordinary, err)
	}
	enabled, err := Load(writeTemp(t, base+"usage_event_store:\n  enabled: true\n  directory: /var/lib/local-events\n  max_bytes: 1048576\n"))
	if err != nil || !enabled.UsageEventStore.Enabled || enabled.UsageEventStore.MaxBytes != 1048576 {
		t.Fatalf("explicit configuration: %+v %v", enabled, err)
	}
	child := Config{}
	child.inheritFrom(enabled)
	if child.UsageEventStore.Enabled || child.UsageEventStore.Directory != "" {
		t.Fatal("implicit root option inheritance")
	}
	enabled.Nodes = []NodeEntry{{NodeID: 7}, {NodeID: 8}}
	nodes := enabled.ExpandNodes()
	if !nodes[0].UsageEventStore.Enabled || !nodes[1].UsageEventStore.Enabled {
		t.Fatal("explicit group's configuration was lost")
	}
	enabled.Machine = &MachineConfig{MachineID: 2, Token: "fixture"}
	if !enabled.ExpandMachineNode(9, "vless").UsageEventStore.Enabled {
		t.Fatal("explicit machine group's configuration was lost")
	}
}
