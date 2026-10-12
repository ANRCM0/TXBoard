// Package txboard implements the native TXBoard node/v1 wire protocol.
// It intentionally does not reuse the Xboard query/body-token transport.
package txboard

import (
 "bytes"
 "encoding/json"
 "errors"
 "fmt"
 "io"
 "net/http"
 "net/url"
 "strconv"
 "strings"
 "sync"
 "sync/atomic"
 "time"

 "github.com/ANRCM0/TX-Node/internal/config"
 "github.com/ANRCM0/TX-Node/internal/panel"
)

const prefix = "/txapi/node/v1"
const maxResponse = 4 << 20

type Handshake struct {
 ProtocolVersion int `json:"protocol_version"`
 NodeID *int `json:"node_id"`
 Mode string `json:"mode"`
 Settings panel.Settings `json:"settings"`
 WebSocket struct {
  Enabled bool `json:"enabled"`
  Path string `json:"path"`
 } `json:"websocket"`
 // Optional capability; the explicit v2 reporter validates it. Service remains v1.
 UsageV2 json.RawMessage `json:"usage_v2,omitempty"`
}
type NodeList struct {
 Nodes []panel.MachineNode `json:"nodes"`
 BaseConfig panel.MachineBaseConfig `json:"base_config"`
}
type Client struct {
 baseURL, token string
 nodeID, machineID int
 http *http.Client
 mu sync.Mutex
 configETag, usersETag string
 success, failure atomic.Uint64
}
func NewClient(cfg config.PanelConfig) *Client {
 return &Client{baseURL: strings.TrimRight(cfg.URL, "/"), token: cfg.Token,
 nodeID: cfg.NodeID, machineID: cfg.MachineID, http: &http.Client{Timeout:30*time.Second}}
}
func (c *Client) ForNode(id int) *Client {
 return &Client{baseURL:c.baseURL, token:c.token, nodeID:id, machineID:c.machineID, http:c.http}
}
func (c *Client) ResetConfigETag() { c.mu.Lock(); c.configETag=""; c.mu.Unlock() }
func (c *Client) ResetETags() { c.mu.Lock(); c.configETag=""; c.usersETag=""; c.mu.Unlock() }
func (c *Client) Metrics() (uint64,uint64) { return c.success.Load(), c.failure.Load() }
func (c *Client) NodeID() int { return c.nodeID }
func (c *Client) MachineID() int { return c.machineID }
func (c *Client) Token() string { return c.token }
func (c *Client) BaseURL() string { return c.baseURL }

// WSSURL accepts only the versioned route advertised by native handshake.
func (c *Client) WSSURL(path string) (string,error) {
 if path != prefix+"/ws" { return "",fmt.Errorf("unexpected native websocket path %q",path) }
 u,err:=url.Parse(c.baseURL)
 if err!=nil {return "",err}
 if u.Scheme=="https" {u.Scheme="wss"} else if u.Scheme=="http" {u.Scheme="ws"} else {return "",errors.New("unsupported panel scheme")}
 u.Path=path
 u.RawQuery=""
 u.Fragment=""
 return u.String(),nil
}
func (c *Client) request(method, path string, body any, etag string) (*http.Response,error) {
 var reader io.Reader
 if body!=nil {
  b,err:=json.Marshal(body);if err!=nil{return nil,err}
  reader=bytes.NewReader(b)
 }
 req,err:=http.NewRequest(method,c.baseURL+prefix+path,reader)
 if err!=nil{return nil,err}
 req.Header.Set("Accept","application/json")
 req.Header.Set("Authorization","Bearer "+c.token)
 if c.machineID>0 {req.Header.Set("X-TX-Machine-ID",strconv.Itoa(c.machineID))}
 if c.nodeID>0 {req.Header.Set("X-TX-Node-ID",strconv.Itoa(c.nodeID))}
 if body!=nil {req.Header.Set("Content-Type","application/json")}
 if etag!="" {req.Header.Set("If-None-Match",etag)}
 resp,err:=c.http.Do(req)
 if err!=nil {c.failure.Add(1);return nil,err}
 if resp.StatusCode>=200&&resp.StatusCode<400 {c.success.Add(1)} else {c.failure.Add(1)}
 return resp,nil
}
func decode(resp *http.Response, dest any, allowed ...int) error {
 defer resp.Body.Close()
 ok:=false;for _,code:=range allowed {if resp.StatusCode==code {ok=true;break}}
 if !ok {
  b,_:=io.ReadAll(io.LimitReader(resp.Body,4096))
  return fmt.Errorf("TXBoard HTTP %d: %s",resp.StatusCode,string(b))
 }
 b,err:=io.ReadAll(io.LimitReader(resp.Body,maxResponse+1))
 if err!=nil {return err}
 if len(b)>maxResponse {return errors.New("TXBoard response too large")}
 var envelope struct { Data json.RawMessage `json:"data"` }
 if err:=json.Unmarshal(b,&envelope);err!=nil{return fmt.Errorf("decode TXAPI envelope: %w",err)}
 if len(envelope.Data)==0||string(envelope.Data)=="null" {return errors.New("missing TXAPI data")}
 return json.Unmarshal(envelope.Data,dest)
}
func (c *Client) Handshake() (*Handshake,error) {
 resp,err:=c.request("POST","/handshake",nil,"")
 if err!=nil{return nil,err}
 var hs Handshake
 if err:=decode(resp,&hs,200);err!=nil{return nil,err}
 if hs.ProtocolVersion!=1 {return nil,fmt.Errorf("unsupported TXBoard protocol version %d",hs.ProtocolVersion)}
 if c.nodeID>0 && hs.NodeID!=nil && *hs.NodeID!=c.nodeID {return nil,errors.New("handshake node mismatch")}
 return &hs,nil
}
func (c *Client) GetConfig() (*panel.NodeConfig,error) {
 c.mu.Lock();etag:=c.configETag;c.mu.Unlock()
 resp,err:=c.request("GET","/config",nil,etag)
 if err!=nil{return nil,err}
 if resp.StatusCode==304 {resp.Body.Close();return nil,nil}
 var data struct {
  ProtocolVersion int `json:"protocol_version"`
  NodeID int `json:"node_id"`
  Config panel.NodeConfig `json:"config"`
 }
 if err:=decode(resp,&data,200);err!=nil{return nil,err}
 if data.ProtocolVersion!=1||data.NodeID!=c.nodeID||data.Config.Protocol=="" {return nil,errors.New("invalid TXBoard config snapshot")}
 c.mu.Lock();c.configETag=resp.Header.Get("ETag");c.mu.Unlock()
 return &data.Config,nil
}
// A nil slice means 304; an allocated empty slice means a valid empty user set.
func (c *Client) GetUsers() ([]panel.User,error) {
 c.mu.Lock();etag:=c.usersETag;c.mu.Unlock()
 resp,err:=c.request("GET","/users",nil,etag)
 if err!=nil{return nil,err}
 if resp.StatusCode==304 {resp.Body.Close();return nil,nil}
 var data struct {
  ProtocolVersion int `json:"protocol_version"`
  NodeID int `json:"node_id"`
  Users []panel.User `json:"users"`
 }
 if err:=decode(resp,&data,200);err!=nil{return nil,err}
 if data.ProtocolVersion!=1||data.NodeID!=c.nodeID {return nil,errors.New("invalid TXBoard users snapshot")}
 c.mu.Lock();c.usersETag=resp.Header.Get("ETag");c.mu.Unlock()
 if data.Users==nil {data.Users=make([]panel.User,0)}
 return data.Users,nil
}
func (c *Client) GetMachineNodes() (*NodeList,error) {
 resp,err:=c.request("GET","/machine/nodes",nil,"")
 if err!=nil{return nil,err}
 var data struct {
  ProtocolVersion int `json:"protocol_version"`
  Nodes []panel.MachineNode `json:"nodes"`
  BaseConfig panel.MachineBaseConfig `json:"base_config"`
 }
 if err:=decode(resp,&data,200);err!=nil{return nil,err}
 if data.ProtocolVersion!=1 {return nil,errors.New("invalid machine node list version")}
 return &NodeList{Nodes:data.Nodes,BaseConfig:data.BaseConfig},nil
}
func (c *Client) PostReport(body map[string]any) error {
 body["protocol_version"]=1
 resp,err:=c.request("POST","/report",body,"")
 if err!=nil{return err}
 var ack struct {
  ProtocolVersion int `json:"protocol_version"`
  Accepted bool `json:"accepted"`
  BatchID string `json:"traffic_batch_id"` 
  Settlement string `json:"settlement"`
 }
 if err:=decode(resp,&ack,202);err!=nil{return err}
 if ack.ProtocolVersion!=1||!ack.Accepted{return errors.New("TXBoard rejected report")}
 if traffic,ok:=body["traffic"];ok && traffic!=nil {
  if id,ok:=body["traffic_batch_id"].(string);ok && id!="" && (ack.BatchID!=id||ack.Settlement!="queued"){return errors.New("invalid TXBoard report ACK")}
 }
 return nil
}
func (c *Client) ReportMachineStatus(body map[string]any) error {
 body["protocol_version"]=1
 resp,err:=c.request("POST","/machine/status",body,"")
 if err!=nil{return err}
 var ack struct{ ProtocolVersion int `json:"protocol_version"`; Accepted bool `json:"accepted"` }
 if err:=decode(resp,&ack,200);err!=nil{return err}
 if ack.ProtocolVersion!=1||!ack.Accepted{return errors.New("TXBoard rejected machine status")}
 return nil
}
