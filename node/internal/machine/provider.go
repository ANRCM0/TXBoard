package machine
import (
 "fmt"
 "github.com/ANRCM0/TX-Node/internal/config"
 "github.com/ANRCM0/TX-Node/internal/panel"
 "github.com/ANRCM0/TX-Node/internal/txboard"
)
func newMachineControlPlane(provider string, cfg config.PanelConfig) (machineControlPlane, error) {
 switch provider {
 case "", "xboard": return newXboardMachineControlPlane(panel.NewClient(cfg)), nil
 case "txboard": return &txboardMachineControlPlane{client:txboard.NewClient(cfg)},nil
 default: return nil, fmt.Errorf("unsupported machine control-plane provider %q", provider)
 }
}
