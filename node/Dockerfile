FROM golang:1.26-alpine AS builder

RUN apk add --no-cache git
WORKDIR /build

COPY go.mod go.sum ./
RUN go mod download
COPY . .

ARG VERSION=dev
ARG BUILD_TIME=unknown
RUN CGO_ENABLED=0 go build -ldflags "-s -w \
    -X main.version=${VERSION} \
    -X main.buildTime=${BUILD_TIME}" \
    -tags "with_quic with_utls with_wireguard with_clash_api" \
    -o tx-node ./cmd/xboard-node

FROM alpine:3.20
RUN apk add --no-cache ca-certificates tzdata
COPY --from=builder /build/tx-node /usr/local/bin/tx-node
RUN ln -s /usr/local/bin/tx-node /usr/local/bin/xboard-node && mkdir -p /etc/xboard-node
WORKDIR /etc/xboard-node
ENTRYPOINT ["tx-node"]
CMD ["-c", "/etc/xboard-node/config.yml"]
