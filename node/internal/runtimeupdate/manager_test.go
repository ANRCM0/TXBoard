package runtimeupdate

import (
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"
)

func TestManagerAvailabilityAndRequest(t *testing.T) {
	dir := t.TempDir()
	m := New(dir)

	if m.Available() {
		t.Fatal("updater must be unavailable without Installer capability")
	}

	if err := os.WriteFile(filepath.Join(dir, "capabilities.env"), []byte(
		"schema=1\nupdater_available=true\ntarget=latest\n",
	), 0o600); err != nil {
		t.Fatal(err)
	}
	if !m.Available() {
		t.Fatal("expected valid capability marker")
	}

	if err := m.Request("mup_dev-old-bridge", "dev"); err == nil { t.Fatal("dev must fail for legacy latest-only bridge") }

	if err := m.Request("mup_test-01", "latest"); err != nil {
		t.Fatalf("Request: %v", err)
	}
	body, err := os.ReadFile(filepath.Join(dir, "request.env"))
	if err != nil {
		t.Fatal(err)
	}
	if string(body) != "schema=1\nrequest_id=mup_test-01\ntarget=latest\n" {
		t.Fatalf("unexpected request body: %q", body)
	}

	info, err := os.Stat(filepath.Join(dir, "request.env"))
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm()&0o077 != 0 {
		t.Fatalf("request file permissions too broad: %o", info.Mode().Perm())
	}
}

func TestValidateRequestRejectsUnboundedInputs(t *testing.T) {
	cases := []struct {
		id     string
		target string
	}{
		{"", "latest"},
		{"../../escape", "latest"},
		{strings.Repeat("a", 65), "latest"},
		{"mup_ok", "v2.3.0"},
		{"mup_ok", "ghcr.io/example/other:latest"},
		{"mup_ok", "v2.3.0-rc.1"},
	}
	for _, tc := range cases {
		if err := ValidateRequest(tc.id, tc.target); err == nil {
			t.Fatalf("ValidateRequest(%q,%q) unexpectedly succeeded", tc.id, tc.target)
		}
	}
}

func TestLastStatusIsBoundedAndValidated(t *testing.T) {
	dir := t.TempDir()
	m := New(dir)
	statusPath := filepath.Join(dir, "status.env")
	now := time.Now().Unix()

	body := "schema=1\nrequest_id=mup_test-02\ntarget=latest\nstatus=rolled_back\nupdated_at=" +
		strconv.FormatInt(now, 10) + "\nmessage=previous image restored\n"
	if err := os.WriteFile(statusPath, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}

	status := m.LastStatus()
	if status == nil {
		t.Fatal("expected valid status")
	}
	if status.Status != "rolled_back" || status.RequestID != "mup_test-02" {
		t.Fatalf("unexpected status: %+v", status)
	}

	if err := os.WriteFile(statusPath, []byte(
		"schema=1\nrequest_id=mup_test-03\ntarget=latest\nstatus=exec\nupdated_at="+
			strconv.FormatInt(now, 10)+"\n",
	), 0o600); err != nil {
		t.Fatal(err)
	}
	if got := m.LastStatus(); got != nil {
		t.Fatalf("unknown state unexpectedly accepted: %+v", got)
	}
}

func TestCapabilityRejectsUnknownOrOversizedData(t *testing.T) {
	dir := t.TempDir()
	m := New(dir)
	path := filepath.Join(dir, "capabilities.env")

	if err := os.WriteFile(path, []byte(
		"schema=1\nupdater_available=true\ntarget=v2.3.0\n",
	), 0o600); err != nil {
		t.Fatal(err)
	}
	if m.Available() {
		t.Fatal("non-latest target must not advertise updater")
	}

	if err := os.WriteFile(path, []byte(strings.Repeat("x", 513)), 0o600); err != nil {
		t.Fatal(err)
	}
	if m.Available() {
		t.Fatal("oversized capability file must be rejected")
	}
}

func TestDevelopmentChannelRequest(t *testing.T) {
 dir := t.TempDir()
 if err := os.WriteFile(filepath.Join(dir, "capabilities.env"), []byte("schema=1\nupdater_available=true\ntarget=latest,dev\n"), 0600); err != nil { t.Fatal(err) }
 m := New(dir)
 if !m.Available() { t.Fatal("two-channel bridge should be available") }
 if err := m.Request("mup_dev-01", "dev"); err != nil { t.Fatal(err) }
 data,err:=os.ReadFile(filepath.Join(dir,"request.env")); if err!=nil {t.Fatal(err)}
 if !strings.Contains(string(data),"target=dev\n") {t.Fatalf("wrong target: %s",data)}
 if err:=ValidateRequest("mup_dev-02","random-image");err==nil {t.Fatal("unbounded target accepted")}
}
