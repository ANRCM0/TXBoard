# Build stage
FROM golang:1.26-alpine AS builder

RUN apk add --no-cache git

WORKDIR /build

COPY go.mod go.sum ./
RUN go mod download

COPY . .

RUN CGO_ENABLED=0 go build -ldflags "-s -w \
    -X github.com/ANRCM0/TX-Node/internal/buildinfo.Version=$(git describe --tags --always --dirty 2>/dev/null || echo dev) \
    -X github.com/ANRCM0/TX-Node/internal/buildinfo.BuildTime=$(date -u +%Y-%m-%dT%H:%M:%SZ) \
    -X github.com/ANRCM0/TX-Node/internal/buildinfo.Commit=$(git rev-parse --short HEAD 2>/dev/null || echo unknown)" \
    -tags "with_quic with_utls with_wireguard with_clash_api" \
    -o tx-node ./cmd/tx-node

# Runtime stage — sing-box & xray-core are embedded as Go libraries
FROM alpine:3.20

RUN apk add --no-cache ca-certificates tzdata

COPY --from=builder /build/tx-node /usr/local/bin/tx-node

# /etc/txnode is the canonical container config root. Keep the legacy
# directory only for the bounded old-Compose bind-mount compatibility window.
RUN mkdir -p /etc/txnode /etc/xboard-node

WORKDIR /etc/txnode

# Config can be provided via file mount OR environment variables.
# Environment-variable bootstrap retains Xboard-compatible defaults. To use
# TXBoard native node/v1 or Machine mode, mount a YAML config with
# panel.provider: txboard (and machine.machine_id/token when applicable).
# Env var mode (no config file needed):
#   docker run -d --network=host \
#     -e apiHost=https://panel.example.com \
#     -e apiKey=YOUR_TOKEN \
#     -e nodeID=1 \
#     ghcr.io/anrcm0/tx-node:latest
#
# Supported env vars:
#   apiHost  / API_HOST    → panel URL
#   apiKey   / API_KEY     → server token
#   nodeID   / NODE_ID     → node ID
#   nodeType / NODE_TYPE   → node type (optional)
#   kernel   / KERNEL_TYPE → singbox (default) or xray
#   domain   / DOMAIN      → TLS domain (enables auto_tls)
#   certFile / CERT_FILE   → TLS cert path
#   keyFile  / KEY_FILE    → TLS key path
#   logLevel / LOG_LEVEL   → log level

ENTRYPOINT ["tx-node"]
CMD ["-c", "/etc/txnode/config.yml"]
