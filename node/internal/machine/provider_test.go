package machine
import (
 "testing"
 "github.com/ANRCM0/TX-Node/internal/config"
)
func TestMachineProviderFactory(t *testing.T) {
 if cp, err := newMachineControlPlane("", config.PanelConfig{}); err != nil || cp == nil { t.Fatalf("default provider: %v", err) }
 if cp, err := newMachineControlPlane("xboard", config.PanelConfig{}); err != nil || cp == nil { t.Fatalf("xboard provider: %v", err) }
 if cp, err := newMachineControlPlane("txboard", config.PanelConfig{}); err != nil || cp == nil { t.Fatalf("txboard provider: %v",err) }
}

func TestUnknownMachineProvider(t *testing.T) {
 cp,err:=newMachineControlPlane("unknown",config.PanelConfig{})
 if cp!=nil||err==nil {t.Fatalf("cp=%T err=%v",cp,err)}
}
