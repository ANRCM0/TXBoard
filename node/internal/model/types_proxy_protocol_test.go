package model

import (
	"reflect"
	"testing"
)

func TestGetProxyProtocolTrustedCIDRs(t *testing.T) {
	tests := []struct {
		name     string
		settings map[string]any
		want     []string
	}{
		{
			name: "camel case list",
			settings: map[string]any{
				"proxyProtocolTrustedCIDRs": []any{"203.0.113.10", "2001:db8::/32"},
			},
			want: []string{"203.0.113.10", "2001:db8::/32"},
		},
		{
			name: "comma separated alias",
			settings: map[string]any{
				"trustedProxyCIDRs": "10.0.0.0/8, 192.0.2.10",
			},
			want: []string{"10.0.0.0/8", "192.0.2.10"},
		},
		{
			name: "snake case",
			settings: map[string]any{
				"proxy_protocol_trusted_cidrs": []string{"127.0.0.1"},
			},
			want: []string{"127.0.0.1"},
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			node := &NodeSpec{NetworkSettings: tt.settings}
			if got := node.GetProxyProtocolTrustedCIDRs(); !reflect.DeepEqual(got, tt.want) {
				t.Fatalf("GetProxyProtocolTrustedCIDRs() = %#v, want %#v", got, tt.want)
			}
		})
	}
}

func TestGetProxyProtocolAcceptNoHeader(t *testing.T) {
	node := &NodeSpec{NetworkSettings: map[string]any{
		"proxyProtocolAcceptNoHeader": true,
	}}
	if !node.GetProxyProtocolAcceptNoHeader() {
		t.Fatal("expected proxyProtocolAcceptNoHeader=true")
	}
}
