package audit

import (
    "bytes"
    "context"
    "encoding/json"
    "errors"
    "fmt"
    "io"
    "net/http"
    "net/url"
    "strconv"
)

// wireAdapter is the only protocol-specific layer in AccessAudit. The
// sing-box observations, rule matching, bounded buffering and flush loop
// remain exactly one implementation for Xboard and TXBoard.
type wireAdapter interface {
    FetchRules(context.Context) ([]rule, error)
    Send(context.Context, []Event) error
}

func (r *Reporter) adapter() wireAdapter {
    if r.auth.Protocol == "txboard" {
        return nativeAuditWire{auth: r.auth, http: r.http}
    }
    return xboardAuditWire{auth: r.auth, http: r.http}
}

type xboardAuditWire struct {
    auth PanelAuth
    http *http.Client
}
type nativeAuditWire struct {
    auth PanelAuth
    http *http.Client
}

func xboardQuery(a PanelAuth) url.Values {
    q := url.Values{}
    q.Set("token", a.Token)
    if a.MachineID > 0 { q.Set("machine_id", strconv.Itoa(a.MachineID)) }
    if a.NodeID > 0 { q.Set("node_id", strconv.Itoa(a.NodeID)) }
    if a.NodeType != "" && a.MachineID == 0 { q.Set("node_type", a.NodeType) }
    return q
}
func xboardPayload(m map[string]interface{}, a PanelAuth) {
    m["token"] = a.Token
    if a.MachineID > 0 { m["machine_id"] = a.MachineID }
    if a.NodeID > 0 { m["node_id"] = a.NodeID }
    if a.NodeType != "" && a.MachineID == 0 { m["node_type"] = a.NodeType }
}
func (a xboardAuditWire) FetchRules(ctx context.Context) ([]rule,error) {
    req,err:=http.NewRequestWithContext(ctx,"GET",a.auth.BaseURL+rulesPath+"?"+xboardQuery(a.auth).Encode(),nil)
    if err!=nil {return nil,err}
    resp,err:=a.http.Do(req)
    if err!=nil {return nil,err}
    defer resp.Body.Close()
    if resp.StatusCode!=200 {return nil,fmt.Errorf("legacy audit rules HTTP %d",resp.StatusCode)}
    var result struct {Data []rule `json:"data"`}
    if err:=json.NewDecoder(io.LimitReader(resp.Body,1<<20)).Decode(&result);err!=nil{return nil,err}
    return result.Data,nil
}
func (a xboardAuditWire) Send(ctx context.Context,batch []Event) error {
    payload:=map[string]interface{}{"events":batch}
    xboardPayload(payload,a.auth)
    return sendAuditJSON(ctx,a.http,a.auth.BaseURL+reportPath,payload,nil)
}

// Native transport never places credentials in a query parameter or JSON body.
// TxNodeAuth middleware verifies the Machine/Node identity and bearer token.
func nativeHeaders(req *http.Request,a PanelAuth) {
    req.Header.Set("Authorization","Bearer "+a.Token)
    req.Header.Set("Accept","application/json")
    req.Header.Set("X-TX-Node-ID",strconv.Itoa(a.NodeID))
    if a.MachineID>0 {req.Header.Set("X-TX-Machine-ID",strconv.Itoa(a.MachineID))}
}
func (a nativeAuditWire) FetchRules(ctx context.Context) ([]rule,error) {
    req,err:=http.NewRequestWithContext(ctx,"GET",a.auth.BaseURL+"/txapi/node/v1/audit/rules",nil)
    if err!=nil {return nil,err}
    nativeHeaders(req,a.auth)
    resp,err:=a.http.Do(req)
    if err!=nil{return nil,err}
    defer resp.Body.Close()
    if resp.StatusCode!=200{return nil,fmt.Errorf("native audit rules HTTP %d",resp.StatusCode)}
    var result struct {
        Data struct {
            ProtocolVersion int `json:"protocol_version"`
            Rules []rule `json:"rules"`
        } `json:"data"`
    }
    if err:=json.NewDecoder(io.LimitReader(resp.Body,1<<20)).Decode(&result);err!=nil{return nil,err}
    if result.Data.ProtocolVersion!=1{return nil,errors.New("unsupported native audit protocol")}
    return result.Data.Rules,nil
}
func (a nativeAuditWire) Send(ctx context.Context,batch []Event) error {
    // Leave Xboard wire JSON intact; event_id exists only in the native API.
    type nativeEvent struct {
        ID string `json:"event_id"`
        UserID int `json:"user_id"`
        Target string `json:"target"`
        TargetIP string `json:"target_ip,omitempty"`
        SourceIP string `json:"source_ip,omitempty"`
        Matched bool `json:"matched"`
    }
    events:=make([]nativeEvent,0,len(batch))
    for _,e:=range batch {
        if e.ID=="" {return errors.New("audit event ID missing")}
        events=append(events,nativeEvent{e.ID,e.UserID,e.Target,e.TargetIP,e.SourceIP,e.Matched})
    }
    reqBody:=map[string]interface{}{"protocol_version":1,"events":events}
    return sendAuditJSON(ctx,a.http,a.auth.BaseURL+"/txapi/node/v1/audit/report",reqBody,func(req *http.Request){nativeHeaders(req,a.auth)})
}
func sendAuditJSON(ctx context.Context,client *http.Client,url string,payload any,authorize func(*http.Request)) error {
    body,err:=json.Marshal(payload);if err!=nil{return err}
    req,err:=http.NewRequestWithContext(ctx,"POST",url,bytes.NewReader(body));if err!=nil{return err}
    req.Header.Set("Content-Type","application/json")
    if authorize!=nil {authorize(req)}
    resp,err:=client.Do(req);if err!=nil{return err}
    defer resp.Body.Close()
    if resp.StatusCode!=200{return fmt.Errorf("audit report HTTP %d",resp.StatusCode)}
    io.Copy(io.Discard,io.LimitReader(resp.Body,4096))
    return nil
}
