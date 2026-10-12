package usagev2

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestHTTPAuthScopeReceiptAndQueryUseSeparateV2Paths(t *testing.T) {
	s := golden(t)
	var calls []string
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "POST" || r.Header.Get("Authorization") != "Bearer transport-secret" || r.Header.Get("X-TX-Node-ID") != "42" || r.Header.Get("X-TX-Machine-ID") != "7" {
			t.Error("missing authenticated scope")
		}
		if r.URL.RawQuery != "" {
			t.Error("unexpected query material")
		}
		calls = append(calls, r.URL.Path)
		if strings.HasSuffix(r.URL.Path, "/submit") {
			var submitted Submission
			if err := json.NewDecoder(r.Body).Decode(&submitted); err != nil {
				t.Error(err)
			}
			if submitted.PayloadDigest != s.PayloadDigest {
				t.Error("changed HTTP payload")
			}
		} else {
			var q Query
			if err := json.NewDecoder(r.Body).Decode(&q); err != nil || q.PayloadDigest != s.PayloadDigest {
				t.Errorf("invalid query %v", err)
			}
		}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(map[string]any{"data": receiptFor(s, "settled"), "request_id": "trace_0001"})
	}))
	defer server.Close()
	transport, err := NewHTTPTransport(server.URL, "transport-secret", "42", 7, server.Client())
	if err != nil {
		t.Fatal(err)
	}
	if _, err := transport.Submit(context.Background(), s); err != nil {
		t.Fatal(err)
	}
	if _, found, err := transport.Get(context.Background(), s.Query()); err != nil || !found {
		t.Fatalf("query %v %v", found, err)
	}
	if len(calls) != 2 || calls[0] != HTTPPrefix+"/submit" || calls[1] != HTTPPrefix+"/batch/get" {
		t.Fatal("v1 report path reused")
	}
}

func TestHTTPRejectsQueuedIncompleteWrongAndDuplicateReceipts(t *testing.T) {
	s := golden(t)
	receiptJSON, _ := json.Marshal(receiptFor(s, "pending"))
	for name, test := range map[string]struct {
		status int
		body   string
	}{
		"v1 queued":             {202, `{"data":{"protocol_version":1,"accepted":true,"settlement":"queued"}}`},
		"untyped 2xx":           {200, `{"data":{"accepted":true}}`},
		"queued typed receipt":  {200, `{"data":` + strings.Replace(string(receiptJSON), `"persisted"`, `"queued"`, 1) + `}`},
		"wrong epoch":           {200, `{"data":` + strings.Replace(string(receiptJSON), `"source_epoch":"1"`, `"source_epoch":"2"`, 1) + `}`},
		"missing accepted time": {200, `{"data":` + strings.Replace(string(receiptJSON), `"accepted_at":1791770462,`, "", 1) + `}`},
		"duplicate digest":      {200, `{"data":` + strings.Replace(string(receiptJSON), `"payload_digest":`, `"payload_digest":"other","payload_digest":`, 1) + `}`},
		"wrong digest":          {200, `{"data":` + strings.Replace(string(receiptJSON), goldenDigest, "sha256:"+strings.Repeat("0", 64), 1) + `}`},
		"unknown secret field":  {200, `{"data":` + strings.Replace(string(receiptJSON), `"receipt_id":`, `"token":"leak","receipt_id":`, 1) + `}`},
	} {
		t.Run(name, func(t *testing.T) {
			server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(test.status); w.Write([]byte(test.body)) }))
			defer server.Close()
			transport, err := NewHTTPTransport(server.URL, "secret", "42", 0, server.Client())
			if err != nil {
				t.Fatal(err)
			}
			if _, err := transport.Submit(context.Background(), s); err == nil {
				t.Fatal("unsafe HTTP ACK accepted")
			}
		})
	}
}

func TestHTTPNotFoundConflictAndRedirectAreDistinct(t *testing.T) {
	s := golden(t)
	for _, status := range []int{404, 409, 307} {
		t.Run(http.StatusText(status), func(t *testing.T) {
			calls := 0
			server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				calls++
				if status == 307 {
					w.Header().Set("Location", "/untrusted")
				}
				w.WriteHeader(status)
				code := "BATCH_NOT_FOUND"
				if status == 409 {
					code = "BATCH_PAYLOAD_CONFLICT"
				}
				json.NewEncoder(w).Encode(map[string]any{"error": map[string]any{"code": code, "message": "upstream-private-material"}})
			}))
			defer server.Close()
			transport, err := NewHTTPTransport(server.URL, "secret", "42", 0, server.Client())
			if err != nil {
				t.Fatal(err)
			}
			_, found, err := transport.Get(context.Background(), s.Query())
			if status == 404 {
				if err != nil || found {
					t.Fatal("authenticated absent batch not distinguished")
				}
			} else {
				if err == nil || strings.Contains(err.Error(), "private-material") {
					t.Fatal("unsafe error or redirected ACK")
				}
				if status == 409 && !errors.Is(err, ErrPayloadConflict) {
					t.Fatal("conflict not explicit")
				}
			}
			if calls != 1 {
				t.Fatal("credentials followed a redirect")
			}
		})
	}
	if _, err := NewHTTPTransport("http://example.test", "secret", "42", 0, nil); err == nil {
		t.Fatal("plaintext usage transport allowed")
	}
}
