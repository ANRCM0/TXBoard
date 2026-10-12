package service

import (
	"context"
	"fmt"

	"github.com/ANRCM0/TX-Node/internal/model"
	"github.com/ANRCM0/TX-Node/internal/nlog"
)

// applyRemoteOverrides keeps generic node-runtime settings in Service while
// delegating all certificate-runtime coordination to certcoord.Coordinator.
func (s *Service) applyRemoteOverrides(ctx context.Context, node *model.NodeSpec) bool {
	if node == nil {
		return false
	}

	if node.KernelLogLevel != "" && node.KernelLogLevel != s.cfg.Kernel.LogLevel {
		nlog.Core().Info(
			"kernel log level override",
			"old", s.cfg.Kernel.LogLevel,
			"new", node.KernelLogLevel,
		)
		s.cfg.Kernel.LogLevel = node.KernelLogLevel
	}

	if s.certs == nil {
		return false
	}

	result, err := s.certs.ApplyNodeConfig(ctx, s.cfg.Cert, node)
	if err != nil {
		mode := s.cfg.Cert.CertMode
		if node.CertConfig != nil {
			mode = node.CertConfig.CertMode
		}
		nlog.Core().Error(
			"failed to apply runtime cert config",
			"mode", mode,
			"error", err,
		)
		return false
	}

	legacyAutoTLSChanged := result.Config.AutoTLS != s.cfg.Cert.AutoTLS
	s.cfg.Cert = result.Config
	if legacyAutoTLSChanged && !result.Reconfigured {
		nlog.Core().Info(
			"cert: auto_tls policy changed (deprecated field)",
			"new", result.Config.AutoTLS,
		)
	}

	if result.Changed {
		msg := fmt.Sprintf("cert: material updated, has_cert=%v", s.certs.HasCert())
		if s.nodeLog != nil {
			s.nodeLog.Info(msg)
		} else {
			nlog.Core().Info(msg)
		}
	}
	return result.Changed
}
