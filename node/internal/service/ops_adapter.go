package service

import (
	"fmt"

	"github.com/ANRCM0/TX-Node/internal/controlplane"
	"github.com/ANRCM0/TX-Node/internal/model"
	"github.com/ANRCM0/TX-Node/internal/nlog"
)

// serviceOpsRuntime is the adapter between the large Service orchestrator and
// the isolated typed Node Ops executor. It deliberately exposes only the
// runtime facts/actions Node Ops is allowed to use.
type serviceOpsRuntime struct {
	service *Service
}

func (a serviceOpsRuntime) CurrentAvailable() bool {
	if a.service == nil {
		return false
	}
	a.service.metricsMu.RLock()
	defer a.service.metricsMu.RUnlock()
	return a.service.lastConfig != nil
}

func (a serviceOpsRuntime) ValidateCurrent() error {
	configSnapshot, _ := a.snapshot()
	if configSnapshot == nil {
		return fmt.Errorf("node config is not available")
	}
	return validateNodeRuntime(
		a.service.cfg,
		a.service.kernel.Protocols(),
		configSnapshot,
		a.service.certs.TLSCert(),
	)
}

func (a serviceOpsRuntime) RestartCurrent() error {
	configSnapshot, usersSnapshot := a.snapshot()
	if configSnapshot == nil {
		return fmt.Errorf("node config is not available")
	}
	if !a.service.startKernel(configSnapshot, usersSnapshot) {
		return fmt.Errorf("kernel restart failed")
	}
	return nil
}

func (a serviceOpsRuntime) ReloadCurrent() error {
	configSnapshot, usersSnapshot := a.snapshot()
	if configSnapshot == nil {
		return fmt.Errorf("node config is not available")
	}
	if a.service.kernelLife == nil {
		return fmt.Errorf("kernel lifecycle coordinator is unavailable")
	}
	return a.service.reloadKernelObserved(configSnapshot, usersSnapshot)
}

func (a serviceOpsRuntime) KernelStatus() (string, bool) {
	if a.service == nil || a.service.kernel == nil {
		return "", false
	}
	return a.service.kernel.Name(), a.service.kernel.IsRunning()
}

func (a serviceOpsRuntime) SystemInfo() map[string]interface{} {
	if a.service == nil {
		return map[string]interface{}{}
	}
	return a.service.wsMetrics()
}

func (a serviceOpsRuntime) ApplicationLogPath() string {
	if a.service == nil || a.service.cfg == nil {
		return ""
	}
	return a.service.cfg.Log.Output
}

func (a serviceOpsRuntime) snapshot() (*model.NodeSpec, []model.UserSpec) {
	if a.service == nil {
		return nil, nil
	}
	a.service.metricsMu.RLock()
	configSnapshot := a.service.lastConfig
	a.service.metricsMu.RUnlock()

	var usersSnapshot []model.UserSpec
	if a.service.users != nil {
		usersSnapshot = a.service.users.Users()
	}
	return configSnapshot, usersSnapshot
}

func (s *Service) sendOpsResult(result controlplane.OpsResult) {
	var client controlplane.PushClient
	if s != nil && s.push != nil {
		client = s.push.Client()
	}

	sender, ok := client.(controlplane.OpsResultSender)
	if !ok || sender == nil {
		nlog.Core().Warn(
			"cannot send ops result: push client has no ops result channel",
			"request_id", result.RequestID,
			"operation", result.Operation,
		)
		return
	}
	sender.SendOpsResult(result)
}
