package audit

import (
    "encoding/json"
    "io"
    "net/http"
    "net/http/httptest"
    "strings"
    "sync"
    "testing"
    "time"
)

func TestNativeTXBoardAuditUsesBearerNodeAndMachineIdentity(t *testing.T) {
    var mu sync.Mutex
    var received []map[string]any
    var rulesAuth, reportAuth bool
    var noQueryCredential bool
    srv:=httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter,req *http.Request) {
        mu.Lock()
        defer mu.Unlock()
        noQueryCredential = noQueryCredential || req.URL.Query().Has("token")
        good := req.Header.Get("Authorization")=="Bearer machine-secret" &&
            req.Header.Get("X-TX-Machine-ID")=="7" && req.Header.Get("X-TX-Node-ID")=="4"
        w.Header().Set("Content-Type","application/json")
        switch req.URL.Path {
        case "/txapi/node/v1/audit/rules":
            rulesAuth = good
            io.WriteString(w,`{"data":{"protocol_version":1,"rules":[{"id":1,"name":"test","match_type":"domain_suffix","match_value":"example.net"}]}}`)
        case "/txapi/node/v1/audit/report":
            reportAuth = good
            var payload struct {
                ProtocolVersion int `json:"protocol_version"`
                Token string `json:"token"`
                Events []map[string]any `json:"events"`
            }
            if err:=json.NewDecoder(req.Body).Decode(&payload);err!=nil{w.WriteHeader(422);return}
            if payload.Token!="" || payload.ProtocolVersion!=1 {w.WriteHeader(422);return}
            received=append(received,payload.Events...)
            io.WriteString(w,`{"data":{"protocol_version":1,"accepted":true}}`)
        default:
            w.WriteHeader(404)
        }
    }))
    defer srv.Close()
    r := &Reporter{
        auth:PanelAuth{Protocol:"txboard",BaseURL:srv.URL,Token:"machine-secret",NodeID:4,MachineID:7},
        cfg:Config{Enabled:true,BatchMax:50,QueueCap:100},
        http:&http.Client{Timeout:5*time.Second},
    }
    r.refreshRules()
    r.Observe(42,"sub.example.net","1.2.3.4")
    r.Observe(42,"unmatched.net","1.2.3.4")
    if !r.flushOnce(){t.Fatal("expected successful native delivery")}
    mu.Lock()
    defer mu.Unlock()
    if !rulesAuth||!reportAuth||noQueryCredential {t.Fatalf("wrong native credentials: rules=%v report=%v query=%v",rulesAuth,reportAuth,noQueryCredential)}
    if len(received)!=1{t.Fatalf("wanted one matched event, got %d",len(received))}
    eid, _ := received[0]["event_id"].(string)
    if len(eid)!=32||strings.Contains(eid,"-"){t.Fatalf("invalid stable event id: %s",eid)}
    if received[0]["target"]!="sub.example.net"{t.Fatalf("unexpected event: %#v",received[0])}
}

func TestNativeAuditRetriesWithSameObservationID(t *testing.T) {
    var mu sync.Mutex
    ids:=[]string{}
    calls:=0
    srv:=httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter,req *http.Request){
        if req.URL.Path!="/txapi/node/v1/audit/report" {io.WriteString(w,`{"data":{"protocol_version":1,"rules":[]}}`);return}
        var payload struct {Events []struct{ID string `json:"event_id"`} `json:"events"`}
        json.NewDecoder(req.Body).Decode(&payload)
        mu.Lock()
        calls++
        if len(payload.Events)>0 {ids=append(ids,payload.Events[0].ID)}
        n:=calls
        mu.Unlock()
        if n==1 {w.WriteHeader(503);return}
        io.WriteString(w,`{"data":{"accepted":true}}`)
    }))
    defer srv.Close()
    r:=&Reporter{auth:PanelAuth{Protocol:"txboard",BaseURL:srv.URL,Token:"tok",NodeID:1},
        cfg:Config{Enabled:true,ReportAll:true,BatchMax:10,QueueCap:10},http:&http.Client{Timeout:5*time.Second}}
    r.Observe(7,"domain.example","192.0.2.2")
    if r.flushOnce(){t.Fatal("first request should fail and requeue")}
    if !r.flushOnce(){t.Fatal("retry should succeed")}
    mu.Lock();defer mu.Unlock()
    if len(ids)!=2||ids[0]!=ids[1]||len(ids[0])!=32 {t.Fatalf("replayed audit event was reidentified: %v",ids)}
}
