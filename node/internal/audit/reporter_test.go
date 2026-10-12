package audit

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"sync"
	"testing"
	"time"
)

func TestMatchOne(t *testing.T) {
	cases := []struct {
		typ, value, target string
		want               bool
	}{
		{"domain", "example.com", "example.com", true},
		{"domain", "example.com", "www.example.com", false},
		{"domain_suffix", "example.com", "example.com", true},
		{"domain_suffix", "example.com", "www.example.com", true},
		{"domain_suffix", "example.com", "notexample.com", false},
		{"keyword", "porn", "free-porn-site.com", true},
		{"keyword", "porn", "example.com", false},
		{"ip_cidr", "1.2.3.0/24", "1.2.3.4", true},
		{"ip_cidr", "1.2.3.0/24", "1.2.4.4", false},
		{"ip_cidr", "1.2.3.4", "1.2.3.4", true}, // 无斜杠 = 精确匹配
		{"unknown", "x", "x", false},
	}
	for _, c := range cases {
		if got := matchOne(c.typ, c.value, c.target); got != c.want {
			t.Errorf("matchOne(%q,%q,%q)=%v want %v", c.typ, c.value, c.target, got, c.want)
		}
	}
}

// TestResolveSizes covers the explicit-value-wins contract: a user who sets
// batch_max/queue_cap explicitly must keep their value, even when it happens
// to equal the non-report_all default.
func TestResolveSizes(t *testing.T) {
	cases := []struct {
		name              string
		cfg               Config
		wantBatch, wantQ  int
	}{
		{
			name: "zero values get default",
			cfg:  Config{},
			wantBatch: defaultBatchMax, wantQ: defaultQueueCap,
		},
		{
			name: "zero values get report_all default",
			cfg:  Config{ReportAll: true},
			wantBatch: reportAllBatchMax, wantQ: reportAllQueueCap,
		},
		{
			name: "explicit 50 kept under report_all",
			// 回归：旧实现用 `== 50` 反推"未配置"，会把这里的 50 改写成 200
			cfg:  Config{ReportAll: true, BatchMax: 50, QueueCap: 5000},
			wantBatch: 50, wantQ: 5000,
		},
		{
			name: "explicit custom kept under report_all",
			cfg:  Config{ReportAll: true, BatchMax: 12, QueueCap: 999},
			wantBatch: 12, wantQ: 999,
		},
		{
			name: "explicit custom kept without report_all",
			cfg:  Config{BatchMax: 7, QueueCap: 321},
			wantBatch: 7, wantQ: 321,
		},
		{
			name: "negative treated as unset",
			cfg:  Config{BatchMax: -1, QueueCap: -5},
			wantBatch: defaultBatchMax, wantQ: defaultQueueCap,
		},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			gotBatch, gotQ := resolveSizes(c.cfg)
			if gotBatch != c.wantBatch {
				t.Errorf("batchMax = %d, want %d", gotBatch, c.wantBatch)
			}
			if gotQ != c.wantQ {
				t.Errorf("queueCap = %d, want %d", gotQ, c.wantQ)
			}
		})
	}
}

// TestNewKeepsExplicitSizes verifies New() preserves explicit sizes end to end.
//
// Uses an httptest server rather than a fake base URL: New() starts the loop
// goroutine immediately, and a bogus host would leave it retrying against a
// dead address for the rest of the test binary's lifetime (polluting other
// tests' logs). A live stub keeps the goroutine harmless.
func TestNewKeepsExplicitSizes(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, req *http.Request) {
		switch req.URL.Path {
		case rulesPath:
			io.WriteString(w, `{"data":[]}`)
		default:
			io.WriteString(w, `{"data":{}}`)
		}
	}))
	defer srv.Close()

	r := New(Config{Enabled: true, ReportAll: true, BatchMax: 50, QueueCap: 5000},
		PanelAuth{BaseURL: srv.URL, Token: "t", NodeID: 1})
	if !r.Enabled() {
		t.Fatal("expected enabled")
	}
	if r.cfg.BatchMax != 50 {
		t.Errorf("BatchMax = %d, want 50 (explicit value must win)", r.cfg.BatchMax)
	}
	if r.cfg.QueueCap != 5000 {
		t.Errorf("QueueCap = %d, want 5000 (explicit value must win)", r.cfg.QueueCap)
	}
}

func TestReporterDisabledByDefault(t *testing.T) {
	r := New(Config{}, PanelAuth{BaseURL: "https://p", Token: "t", NodeID: 1})
	if r.Enabled() {
		t.Fatal("expected disabled reporter with Enabled=false config")
	}
	// no-op must not panic
	r.Observe(1, "example.com", "1.1.1.1")
}

func TestReportAllQueuesMisses(t *testing.T) {
	// 无 httptest，直接验证入队逻辑：report_all=true 时未命中也入队
	r := New(Config{Enabled: false, ReportAll: true}, PanelAuth{})
	if r.Enabled() {
		t.Fatal("expected disabled (no auth)")
	}
	// 构造一个手动启用但不联网的 reporter
	r2 := &Reporter{cfg: Config{Enabled: true, ReportAll: true, QueueCap: 10}}
	r2.http = &http.Client{} // 仅作 Enabled() 判定，不实际请求
	r2.Observe(7, "miss.example.org", "1.1.1.1") // 无规则 → matched=false
	r2.queueMu.Lock()
	defer r2.queueMu.Unlock()
	if len(r2.queue) != 1 {
		t.Fatalf("expected 1 queued event (report_all), got %d", len(r2.queue))
	}
	if r2.queue[0].Matched {
		t.Error("expected Matched=false for non-matching target")
	}
	// report_all=false 时未命中不入队
	r3 := &Reporter{cfg: Config{Enabled: true, ReportAll: false, QueueCap: 10}}
	r3.http = &http.Client{}
	r3.Observe(7, "miss.example.org", "1.1.1.1")
	if n := len(r3.queue); n != 0 {
		t.Fatalf("expected 0 queued events (report_all=false), got %d", n)
	}
}

func TestReporterDisabledWithoutAuth(t *testing.T) {
	r := New(Config{Enabled: true}, PanelAuth{})
	if r.Enabled() {
		t.Fatal("expected disabled when panel auth missing")
	}
}

func TestReporterObserveAndFlush(t *testing.T) {
	var mu sync.Mutex
	var gotRulesAuth, gotReport map[string]interface{}
	var reported []Event

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, req *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		switch req.URL.Path {
		case rulesPath:
			gotRulesAuth = map[string]interface{}{
				"token":   req.URL.Query().Get("token"),
				"node_id": req.URL.Query().Get("node_id"),
			}
			w.Header().Set("Content-Type", "application/json")
			io.WriteString(w, `{"data":[{"id":1,"name":"t","match_type":"domain_suffix","match_value":"bad.com\nEVIL.org"}]}`)
		case reportPath:
			body, _ := io.ReadAll(req.Body)
			var payload struct {
				Token  string  `json:"token"`
				NodeID int     `json:"node_id"`
				Events []Event `json:"events"`
			}
			json.Unmarshal(body, &payload)
			gotReport = map[string]interface{}{"token": payload.Token, "node_id": payload.NodeID}
			reported = append(reported, payload.Events...)
			w.Header().Set("Content-Type", "application/json")
			io.WriteString(w, `{"data":{"received":1,"matched":1,"banned":0}}`)
		default:
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	defer srv.Close()

	r := New(Config{Enabled: true, FlushInterval: 1, RulesRefresh: 1},
		PanelAuth{BaseURL: srv.URL, Token: "tok123", NodeID: 7, NodeType: "shadowsocks"})
	if !r.Enabled() {
		t.Fatal("expected enabled reporter")
	}

	// wait for initial rule pull
	deadline := time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		if p := r.rules.Load(); p != nil && len(*p) > 0 {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}

	r.Observe(42, "sub.bad.com", "9.9.9.9")  // hit
	r.Observe(42, "good.com", "9.9.9.9")     // miss
	r.Observe(42, "a.evil.org", "")          // hit (case-insensitive rule value)
	r.Observe(0, "bad.com", "")              // invalid user

	// wait for flush
	deadline = time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		mu.Lock()
		n := len(reported)
		mu.Unlock()
		if n >= 2 {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}

	mu.Lock()
	defer mu.Unlock()
	if len(reported) != 2 {
		t.Fatalf("expected 2 reported events, got %d: %+v", len(reported), reported)
	}
	if reported[0].UserID != 42 || reported[0].Target != "sub.bad.com" || reported[0].SourceIP != "9.9.9.9" {
		t.Errorf("unexpected event[0]: %+v", reported[0])
	}
	// 认证必须复用节点 token/node_id
	if gotRulesAuth["token"] != "tok123" || gotRulesAuth["node_id"] != "7" {
		t.Errorf("rules auth wrong: %+v", gotRulesAuth)
	}
	if gotReport == nil || gotReport["token"] != "tok123" || gotReport["node_id"] != 7 {
		t.Errorf("report auth wrong: %+v", gotReport)
	}
}

// TestNoRulesDropsEverything 锁定「规则为空 + report_all=false」这一组合的
// 真实行为：Observe 必须静默丢弃每一个连接。
//
// 这不是期望行为，而是需要被记录在案的既成事实 —— 生产上最常见的踩坑就是
// 用户开了 enabled 却没配任何规则，界面看起来"已启用"但一条数据都不上报。
// 一旦有人改动 Observe 的过滤逻辑（例如让空规则表放行），这里会立刻告警。
func TestNoRulesDropsEverything(t *testing.T) {
	r := &Reporter{cfg: Config{Enabled: true, ReportAll: false, QueueCap: 10}}
	r.http = &http.Client{} // 仅用于 Enabled() 判定，不发请求

	// 规则表从未被填充：match() 走 p == nil 分支恒返回 false
	r.Observe(7, "example.com", "1.1.1.1")
	r.Observe(7, "1.2.3.4", "1.1.1.1")

	if n := len(r.queue); n != 0 {
		t.Fatalf("expected 0 queued (no rules, report_all=false), got %d", n)
	}
	if st := r.Stats(); st.Reported != 0 || st.Dropped != 0 {
		t.Errorf("expected no reported/dropped counters to move, got %+v", st)
	}

	// 反面：同为空规则表，report_all=true 必须全部放行
	r2 := &Reporter{cfg: Config{Enabled: true, ReportAll: true, QueueCap: 10}}
	r2.http = &http.Client{}
	r2.Observe(7, "example.com", "1.1.1.1")
	if n := len(r2.queue); n != 1 {
		t.Fatalf("expected 1 queued (report_all=true), got %d", n)
	}
	if r2.queue[0].Matched {
		t.Error("expected Matched=false with an empty rule set")
	}
}

// TestWarnNoRulesIsThrottled 验证空规则告警的节流。
//
// refreshRules 默认每 5 分钟跑一次；若不做节流，一台"就是不用规则"的节点会
// 永久地每 5 分钟刷一条 Warn。
//
// 注意断言必须避开秒级精度陷阱：warnNoRules 写入的是 time.Now().Unix()，
// 两次调用落在同一秒内会得到相同的时间戳，因此"刷新"无法通过值是否变化来判定。
// 这里改为验证节流本身的语义 —— 回拨后再次调用应当把时间戳推回到"当前"。
func TestWarnNoRulesIsThrottled(t *testing.T) {
	r := &Reporter{cfg: Config{Enabled: true, ReportAll: false}}
	r.http = &http.Client{}

	r.warnNoRules()
	now := r.lastNoRulesWarn.Load()
	if now == 0 {
		t.Fatal("expected lastNoRulesWarn to be set on first call")
	}

	// 场景 1：立即再调一次 —— 落在节流窗口内，不得改写时间戳
	r.lastNoRulesWarn.Store(now - 30) // 30s 前，仍在 60s 窗口内
	r.warnNoRules()
	if got := r.lastNoRulesWarn.Load(); got != now-30 {
		t.Error("expected the call inside the throttle window to leave the timestamp untouched")
	}

	// 场景 2：时间戳早于节流窗口 —— 必须被刷新为当前时间
	r.lastNoRulesWarn.Store(now - 61)
	r.warnNoRules()
	if got := r.lastNoRulesWarn.Load(); got <= now-61 {
		t.Errorf("expected the timestamp to advance after the window elapsed, got %d", got)
	}
}

// TestRefreshRulesWarnsOnEmptySet 端到端验证：面板返回空规则列表且
// report_all=false 时，refreshRules 必须设置 lastNoRulesWarn（即真的告警），
// 而不是像以前那样只在 Debug 级别留一行看不见的日志。
func TestRefreshRulesWarnsOnEmptySet(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, req *http.Request) {
		if req.URL.Path != rulesPath {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		io.WriteString(w, `{"data":[]}`) // 面板上一条启用规则都没有
	}))
	defer srv.Close()

	r := &Reporter{
		cfg:  Config{Enabled: true, ReportAll: false},
		auth: PanelAuth{BaseURL: srv.URL, Token: "t", NodeID: 3},
		http: &http.Client{Timeout: 5 * time.Second},
	}
	r.refreshRules()

	if r.lastNoRulesWarn.Load() == 0 {
		t.Fatal("expected refreshRules to emit the no-rules warning on an empty rule set")
	}

	// 规则非空时不得告警
	r2 := &Reporter{
		cfg:  Config{Enabled: true, ReportAll: false},
		auth: PanelAuth{BaseURL: srv.URL, Token: "t", NodeID: 3},
		http: &http.Client{Timeout: 5 * time.Second},
	}
	srv2 := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, req *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		io.WriteString(w, `{"data":[{"id":1,"name":"r","match_type":"keyword","match_value":"x"}]}`)
	}))
	defer srv2.Close()
	r2.auth.BaseURL = srv2.URL
	r2.refreshRules()
	if r2.lastNoRulesWarn.Load() != 0 {
		t.Error("did not expect the no-rules warning when rules are present")
	}
	if p := r2.rules.Load(); p == nil || len(*p) != 1 {
		t.Errorf("expected 1 rule cached, got %v", p)
	}
}


func TestReporterRequeueOnFailure(t *testing.T) {
	var mu sync.Mutex
	fail := true
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, req *http.Request) {
		if req.URL.Path == rulesPath {
			io.WriteString(w, `{"data":[{"id":1,"name":"t","match_type":"domain","match_value":"x.com"}]}`)
			return
		}
		mu.Lock()
		f := fail
		mu.Unlock()
		if f {
			w.WriteHeader(http.StatusBadGateway)
			return
		}
		io.WriteString(w, `{"data":{"received":1,"matched":1,"banned":0}}`)
	}))
	defer srv.Close()

	r := New(Config{Enabled: true, FlushInterval: 1, RulesRefresh: 60},
		PanelAuth{BaseURL: srv.URL, Token: "t", NodeID: 1})

	// wait for rules
	deadline := time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		if p := r.rules.Load(); p != nil && len(*p) > 0 {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}

	r.Observe(1, "x.com", "")
	// first flush fails → requeue
	time.Sleep(1500 * time.Millisecond)
	queued := r.Stats().Queued
	if queued != 1 {
		t.Fatalf("expected 1 requeued event, got %d", queued)
	}

	mu.Lock()
	fail = false
	mu.Unlock()
	deadline = time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		if queued = r.Stats().Queued; queued == 0 {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	if queued != 0 {
		t.Fatal("expected queue drained after recovery")
	}
}

// TestObserveDropsWhenQueueFull 回归：队列满时必须丢弃并计数，
// 而不是静默丢弃——静默丢弃曾让 report_all 模式的背压完全不可见。
func TestObserveDropsWhenQueueFull(t *testing.T) {
	r := &Reporter{cfg: Config{Enabled: true, ReportAll: true, QueueCap: 3}}
	r.http = &http.Client{}

	for i := 0; i < 10; i++ {
		r.Observe(i+1, "miss.example.org", "1.1.1.1")
	}

	if queued := r.Stats().Queued; queued != 3 {
		t.Fatalf("queued = %d, want 3 (QueueCap)", queued)
	}
	if got := r.Stats().Dropped; got != 7 {
		t.Fatalf("Dropped = %d, want 7", got)
	}
}

// TestFlushAllDrainsMultipleBatches 回归：一次 flush 必须连续发送多批，
// 直到队列空。旧实现每个 tick 只发 BatchMax 条，report_all 下必然堆积到溢出。
func TestFlushAllDrainsMultipleBatches(t *testing.T) {
	var mu sync.Mutex
	var batches []int

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, req *http.Request) {
		if req.URL.Path == rulesPath {
			io.WriteString(w, `{"data":[]}`)
			return
		}
		body, _ := io.ReadAll(req.Body)
		var payload struct {
			Events []Event `json:"events"`
		}
		json.Unmarshal(body, &payload)
		mu.Lock()
		batches = append(batches, len(payload.Events))
		mu.Unlock()
		io.WriteString(w, `{"data":{"received":0,"matched":0,"banned":0}}`)
	}))
	defer srv.Close()

	r := New(Config{Enabled: true, ReportAll: true, BatchMax: 10, QueueCap: 1000, FlushInterval: 60, RulesRefresh: 60},
		PanelAuth{BaseURL: srv.URL, Token: "t", NodeID: 1})
	if !r.Enabled() {
		t.Fatal("expected enabled")
	}

	for i := 0; i < 45; i++ {
		r.Observe(i+1, "miss.example.org", "1.1.1.1")
	}

	r.flushAll()

	mu.Lock()
	defer mu.Unlock()
	// 45 条 / 每批 10 条 → 5 批，最后一批 5 条
	if len(batches) != 5 {
		t.Fatalf("expected 5 batches, got %d: %v", len(batches), batches)
	}
	total := 0
	for _, n := range batches {
		total += n
	}
	if total != 45 {
		t.Fatalf("expected 45 events delivered, got %d", total)
	}
	if left := r.Stats().Queued; left != 0 {
		t.Fatalf("queue not drained, %d left", left)
	}
}

// TestFlushAllStopsOnFailure 一批失败后必须立刻停止本 tick，
// 否则会在面板故障时打出 maxBatchesPerFlush 次无意义的失败请求。
func TestFlushAllStopsOnFailure(t *testing.T) {
	var mu sync.Mutex
	var posts int

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, req *http.Request) {
		if req.URL.Path == rulesPath {
			io.WriteString(w, `{"data":[]}`)
			return
		}
		mu.Lock()
		posts++
		mu.Unlock()
		w.WriteHeader(http.StatusBadGateway)
	}))
	defer srv.Close()

	r := New(Config{Enabled: true, ReportAll: true, BatchMax: 10, QueueCap: 1000, FlushInterval: 60, RulesRefresh: 60},
		PanelAuth{BaseURL: srv.URL, Token: "t", NodeID: 1})

	for i := 0; i < 45; i++ {
		r.Observe(i+1, "miss.example.org", "1.1.1.1")
	}
	r.flushAll()

	mu.Lock()
	got := posts
	mu.Unlock()
	if got != 1 {
		t.Fatalf("expected exactly 1 POST before bailing out, got %d", got)
	}
	if st := r.Stats(); st.Failed != 1 || st.Queued != 45 {
		t.Fatalf("stats = %+v, want Failed=1 Queued=45", st)
	}
}
