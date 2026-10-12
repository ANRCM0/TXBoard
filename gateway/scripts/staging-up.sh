#!/usr/bin/env bash
# GW-216: Staging 环境一键启动脚本
#
# 用法：
#   scripts/staging-up.sh [--build] [--verbose]
#
# 前置：
#   - Docker + Docker Compose v2
#   - TXBoard staging 网络已存在（或自动创建）
#   - .env 或环境变量已配置

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
COMPOSE_FILE="staging/docker-compose.staging.yml"
COMPOSE_PROJECT="txboard-gateway-staging"
ENV_FILE="$REPO_ROOT/.env"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

BUILD=false
VERBOSE=false
NETWORK_ONLY=false
SKIP_VERIFY=false

for arg in "$@"; do
  case "$arg" in
    --build)       BUILD=true ;;
    --verbose)     VERBOSE=true ;;
    --network-only) NETWORK_ONLY=true ;;
    --skip-verify) SKIP_VERIFY=true ;;
    -h|--help)
      echo "用法: scripts/staging-up.sh [--build] [--verbose] [--network-only] [--skip-verify]"
      echo ""
      echo "选项："
      echo "  --build        强制重新构建镜像"
      echo "  --verbose      显示详细日志"
      echo "  --network-only 仅创建网络，不启动服务"
      echo "  --skip-verify  跳过启动后验证（仅启动服务）"
      exit 0
      ;;
    *) echo "Unknown argument: $arg"; exit 2 ;;
  esac
done

info() { echo -e "${GREEN}[INFO]${NC} $*"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $*"; }
error() { echo -e "${RED}[ERROR]${NC} $*"; exit 1; }

# 加载 .env
load_env() {
  if [[ -f "$ENV_FILE" ]]; then
    info "加载环境变量: $ENV_FILE"
    set -a
    # 安全加载 .env，只导出 KEY=VALUE 格式
    while IFS='=' read -r key value || [[ -n "$key" ]]; do
      # 跳过注释和空行
      [[ "$key" =~ ^[[:space:]]*# ]] && continue
      [[ -z "$key" ]] && continue
      # 去除首尾空白
      key="$(echo "$key" | xargs)"
      value="$(echo "$value" | xargs)"
      # 只导出合法变量名
      if [[ "$key" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ ]]; then
        export "$key=$value"
      fi
    done < "$ENV_FILE"
    set +a
  else
    warn "未找到 $ENV_FILE，使用当前环境变量"
  fi
}

# 检查前置条件
preflight() {
  info "检查前置条件..."

  if ! command -v docker >/dev/null 2>&1; then
    error "docker 不可用"
  fi

  if ! docker compose version >/dev/null 2>&1; then
    error "Docker Compose v2 不可用"
  fi

  # 检查必要的环境变量
  if [[ -z "${TXBOARD_STAGING_UPSTREAM_URL:-}" ]]; then
    error "TXBOARD_STAGING_UPSTREAM_URL 未设置（在 .env 中配置或导出环境变量）"
  fi
  info "上游: $TXBOARD_STAGING_UPSTREAM_URL"

  if [[ -z "${GATEWAY_STAGING_ALLOWED_ORIGINS:-}" ]]; then
    error "GATEWAY_STAGING_ALLOWED_ORIGINS 未设置"
  fi
  info "Origin: $GATEWAY_STAGING_ALLOWED_ORIGINS"

  # 检查 TXBoard staging 网络
  local network="${TXBOARD_STAGING_NETWORK:-txboard-staging_default}"
  if ! docker network inspect "$network" >/dev/null 2>&1; then
    warn "TXBoard staging 网络 '$network' 不存在"
    info "创建网络: $network"
    docker network create "$network"
  fi
  info "网络: $network"

  # 检查 HPKE 密钥文件（仅在启用 HPKE 时）
  local hpke_mode="${GATEWAY_HPKE_MODE:-disabled}"
  if [[ "$hpke_mode" != "disabled" ]]; then
    local hpke_file="${GATEWAY_HPKE_SECRET_FILE:-./secrets/gateway-hpke.json}"
    if [[ ! -f "$hpke_file" ]]; then
      error "HPKE 已启用 ($hpke_mode) 但密钥文件不存在: $hpke_file"
      info "生成密钥: node scripts/generate-hpke-key.mjs"
      info "然后: sudo chown 1000:1000 $hpke_file && sudo chmod 600 $hpke_file"
    fi
    if [[ ! -r "$hpke_file" ]]; then
      error "HPKE 密钥文件不可读: $hpke_file"
    fi
    info "HPKE 密钥文件: $hpke_file"
  fi
}

# 构建镜像
build_image() {
  if $BUILD; then
    info "构建 Gateway 镜像..."
    docker compose -f "$COMPOSE_FILE" build gateway
  fi
}

# 启动服务
start_services() {
  info "启动 staging 服务..."
  docker compose -f "$COMPOSE_FILE" up -d --remove-orphans

  info "等待 Gateway 健康检查..."
  local retries=30
  local i=0
  while [[ $i -lt $retries ]]; do
    if docker exec "${COMPOSE_PROJECT}-gateway-1" node -e "fetch('http://127.0.0.1:8787/healthz').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))" 2>/dev/null; then
      info "Gateway 健康检查通过"
      break
    fi
    i=$((i+1))
    if [[ $i -eq $retries ]]; then
      error "Gateway 健康检查超时"
    fi
    sleep 2
  done

  info "服务状态:"
  docker compose -f "$COMPOSE_FILE" ps
}

# 验证
verify() {
  info "验证 staging 环境..."

  # Gateway 健康
  if docker exec "${COMPOSE_PROJECT}-gateway-1" node -e "fetch('http://127.0.0.1:8787/healthz').then(r=>r.json()).then(j=>console.log(JSON.stringify(j))).catch(()=>process.exit(1))" 2>/dev/null; then
    info "Gateway /healthz: OK"
  else
    error "Gateway /healthz 不可达"
  fi

  # Redis 连接
  if docker exec "${COMPOSE_PROJECT}-gateway-redis-1" redis-cli ping 2>/dev/null | grep -q "PONG"; then
    info "Redis: OK"
  else
    warn "Redis 未就绪（HPKE 可能不可用）"
  fi

  # 上游可达性
  local upstream="${TXBOARD_STAGING_UPSTREAM_URL:-http://txboard}"
  if docker exec "${COMPOSE_PROJECT}-gateway-1" node -e "
    fetch('$upstream', {signal: AbortSignal.timeout(5000)})
      .then(r => process.exit(0))
      .catch(() => process.exit(1));
  " 2>/dev/null; then
    info "上游可达: $upstream"
  else
    warn "上游不可达: $upstream（请确认 TXBoard staging 已启动）"
  fi

  echo ""
  info "════════════════════════════════════════════════"
  info "  Staging 环境已启动"
  info "════════════════════════════════════════════════"
  info ""
  info "Gateway: http://txboard-gateway:8787 (私网)"
  info "上游:    $upstream"
  info "网络:    ${TXBOARD_STAGING_NETWORK:-txboard-staging_default}"
  info ""
  info "下一步:"
  info "  1. 在代理层配置 /gateway/v1/* → Gateway"
  info "  2. 运行冒烟测试: GATEWAY_SMOKE_URL=https://staging.example.test npm run smoke:staging"
  info "  3. 运行回滚演练: scripts/rollback-drill.sh"
  info ""
  info "回滚:"
  info "  docker compose -f $COMPOSE_FILE down"
}

# 主流程
main() {
  echo "════════════════════════════════════════════════"
  echo "  GW-216: TXBoard Gateway Staging 一键启动"
  echo "════════════════════════════════════════════════"
  echo ""

  cd "$REPO_ROOT"
  load_env
  preflight

  if $NETWORK_ONLY; then
    info "仅创建网络，跳过服务启动"
    exit 0
  fi

  build_image
  start_services
  if $SKIP_VERIFY; then
    info "跳过验证（--skip-verify）"
  else
    verify
  fi
}

main "$@"
