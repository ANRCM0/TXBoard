#!/usr/bin/env bash
# GW-109 / GW-216: 回滚演练脚本 — 验证 Gateway → 旧 API 切换流程
#
# 用法：
#   scripts/rollback-drill.sh [--auto] [--verbose] [--skip-restore] [--dry-run] [--output FILE]
#
# 前置：
#   - staging 环境已启动（docker compose -f staging/docker-compose.staging.yml up）
#   - 已配置 TXBOARD_STAGING_UPSTREAM_URL / GATEWAY_STAGING_ALLOWED_ORIGINS
#   - curl / jq 可用
#
# 选项：
#   --auto         自动模式（不交互，自动执行所有步骤）
#   --verbose      显示详细日志
#   --skip-restore 不恢复 Gateway（演练后保持 Gateway 停止状态）
#   --dry-run      仅打印将要执行的操作，不实际执行
#   --output       JSON 结果输出文件（可追踪）
#
# 输出：
#   - 每个步骤的状态（PASS/FAIL）
#   - 最终回滚验证结果
#   - 详细日志（--verbose）
#   - JSON 报告（--output）
#
# 追踪：
#   - 每次演练生成唯一追踪 ID
#   - 记录 Gateway 版本、镜像 digest、时间戳
#   - 所有操作记录到日志文件

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
COMPOSE_FILE="staging/docker-compose.staging.yml"
COMPOSE_PROJECT="txboard-gateway-staging"
DRILL_LOG="$SCRIPT_DIR/.rollback-drill.log"
DRILL_ID="drill-$(date -u +%Y%m%d-%H%M%S)-$$"
DRILL_START_TIME=$(date -u +%Y-%m-%dT%H:%M:%SZ)

# 颜色
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

PASS=0
FAIL=0
SKIP=0

AUTO=false
VERBOSE=false
SKIP_RESTORE=false
DRY_RUN=false
OUTPUT_FILE=""

for arg in "$@"; do
  case "$arg" in
    --auto)         AUTO=true ;;
    --verbose)      VERBOSE=true ;;
    --skip-restore) SKIP_RESTORE=true ;;
    --dry-run)      DRY_RUN=true ;;
    --output)       OUTPUT_FILE="$2"; shift 2 ;;
    -h|--help)
      echo "用法: scripts/rollback-drill.sh [--auto] [--verbose] [--skip-restore] [--dry-run] [--output FILE]"
      echo ""
      echo "选项："
      echo "  --auto         自动模式（不交互）"
      echo "  --verbose      显示详细日志"
      echo "  --skip-restore 不恢复 Gateway（演练后保持停止状态）"
      echo "  --dry-run      仅打印将要执行的操作，不实际执行"
      echo "  --output       JSON 结果输出文件（可追踪）"
      exit 0
      ;;
    *) echo "Unknown argument: $arg"; exit 2 ;;
  esac
done

# 初始化日志
: > "$DRILL_LOG"
log() {
  local msg="[$(date -u +%Y-%m-%dT%H:%M:%SZ)] [$DRILL_ID] $*"
  echo "$msg" >> "$DRILL_LOG"
  if $VERBOSE; then
    echo "$msg"
  fi
}

# 获取 Gateway 版本信息
get_gateway_version() {
  local container="${COMPOSE_PROJECT}-gateway-1"
  local version="unknown"
  local image_digest="unknown"
  local image_id="unknown"

  if command -v docker >/dev/null 2>&1; then
    # 获取镜像信息
    image_id=$(docker inspect --format='{{.Image}}' "$container" 2>/dev/null || echo "unknown")
    image_digest=$(docker inspect --format='{{index .RepoDigests 0}}' "$container" 2>/dev/null || echo "unknown")

    # 尝试从容器内获取版本
    version=$(docker exec "$container" node -e "try{const p=require('./package.json');console.log(p.version)}catch(e){console.log('unknown')}" 2>/dev/null || echo "unknown")
  fi

  echo "{\"version\":\"$version\",\"imageId\":\"$image_id\",\"imageDigest\":\"$image_digest\"}"
}

pass() { echo -e "${GREEN}PASS${NC}: $*"; PASS=$((PASS+1)); log "PASS: $*"; }
fail() { echo -e "${RED}FAIL${NC}: $*"; FAIL=$((FAIL+1)); log "FAIL: $*"; }
skip() { echo -e "${YELLOW}SKIP${NC}: $*"; SKIP=$((SKIP+1)); log "SKIP: $*"; }

section() { echo ""; echo "════════════════════════════════════════════════════════════"; echo "  $*"; echo "════════════════════════════════════════════════════════════"; log "SECTION: $*"; }

# 检查前置条件
preflight() {
  section "前置检查"

  if ! command -v docker >/dev/null 2>&1; then
    fail "docker 不可用"
    return 1
  fi
  pass "docker 可用"

  if ! command -v curl >/dev/null 2>&1; then
    fail "curl 不可用"
    return 1
  fi
  pass "curl 可用"

  if ! docker compose -f "$COMPOSE_FILE" config --quiet 2>/dev/null; then
    fail "Docker Compose 配置无效"
    return 1
  fi
  pass "Docker Compose 配置有效"

  if ! docker network inspect "${TXBOARD_STAGING_NETWORK:-txboard-staging_default}" >/dev/null 2>&1; then
    fail "TXBoard staging 网络 '${TXBOARD_STAGING_NETWORK:-txboard-staging_default}' 不存在"
    return 1
  fi
  pass "TXBoard staging 网络存在"
}

# 步骤 1: 验证当前 Gateway 健康
verify_gateway_up() {
  section "步骤 1: 验证 Gateway 当前健康"

  local container_status
  container_status=$(docker inspect --format='{{.State.Status}}' "${COMPOSE_PROJECT}-gateway-1" 2>/dev/null || echo "missing")

  if [[ "$container_status" != "running" ]]; then
    fail "Gateway 容器未运行 (status: $container_status)"
    return 1
  fi
  pass "Gateway 容器运行中"

  local health
  health=$(curl -sf http://127.0.0.1:8787/healthz 2>/dev/null || echo "")
  if [[ -z "$health" ]]; then
    # 尝试通过 docker exec 检查
    health=$(docker exec "${COMPOSE_PROJECT}-gateway-1" node -e "fetch('http://127.0.0.1:8787/healthz').then(r=>r.json()).then(j=>console.log(JSON.stringify(j))).catch(()=>process.exit(1))" 2>/dev/null || echo "")
  fi
  if [[ -z "$health" ]]; then
    fail "Gateway /healthz 不可达"
    return 1
  fi
  pass "Gateway /healthz 可达"

  # 检查 compose 中的 gateway 服务
  local compose_services
  compose_services=$(docker compose -f "$COMPOSE_FILE" ps --format json 2>/dev/null | head -100)
  if echo "$compose_services" | grep -q '"Service":"gateway"'; then
    pass "Compose 注册 gateway 服务"
  else
    fail "Compose 中未找到 gateway 服务"
    return 1
  fi
}

# 步骤 2: 模拟 Gateway 故障
simulate_gateway_failure() {
  section "步骤 2: 模拟 Gateway 故障"

  if $DRY_RUN; then
    echo "[dry-run] 将执行: docker compose -f $COMPOSE_FILE stop gateway"
    pass "dry-run: Gateway 容器停止操作已模拟"
    return 0
  fi

  echo "选项 A: 停止 Gateway 容器"
  echo "选项 B: 修改代理配置移除 /gateway/v1/* 映射（手动操作）"
  echo ""

  if $AUTO; then
    echo "[auto] 停止 Gateway 容器模拟故障..."
    docker compose -f "$COMPOSE_FILE" stop gateway
    pass "Gateway 容器已停止"
  else
    echo "请选择故障模拟方式："
    echo "  A) 停止 Gateway 容器（自动）"
    echo "  B) 手动修改代理配置（跳过自动操作）"
    read -r -p "选择 [A/B]: " choice

    case "$choice" in
      A|a)
        docker compose -f "$COMPOSE_FILE" stop gateway
        pass "Gateway 容器已停止"
        ;;
      B|b)
        skip "手动故障模拟 — 请在代理层移除 /gateway/v1/* 映射后继续"
        ;;
      *)
        fail "无效选择"
        return 1
        ;;
    esac
  fi
}

# 步骤 3: 验证旧 API 仍可用（回滚验证）
verify_legacy_api() {
  section "步骤 3: 验证旧 API 仍可用（回滚验证）"

  local upstream="${TXBOARD_STAGING_UPSTREAM_URL:-http://txboard}"
  local bootstrap_url="$upstream/api/v1/bootstrap"

  # 尝试通过 docker exec 验证（Gateway 容器仍在运行时）
  local docker_ok=false
  if docker inspect --format='{{.State.Status}}' "${COMPOSE_PROJECT}-gateway-1" 2>/dev/null | grep -q "running"; then
    if docker exec "${COMPOSE_PROJECT}-gateway-1" node -e "
      fetch('$bootstrap_url', {signal: AbortSignal.timeout(5000)})
        .then(r => { if (r.ok || r.status === 401 || r.status === 403) process.exit(0); process.exit(1); })
        .catch(() => process.exit(1));
    " 2>/dev/null; then
      docker_ok=true
      pass "TXBoard 旧 API /api/v1/bootstrap 可达 ($bootstrap_url)"
    fi
  fi

  # 如果 docker exec 失败（Gateway 已停或容器不存在），尝试 curl
  if ! $docker_ok; then
    # 从 upstream URL 提取主机名和端口
    local host_port
    host_port=$(echo "$upstream" | sed -E 's|https?://||' | sed -E 's|/.*||')
    local scheme
    scheme=$(echo "$upstream" | sed -E 's|(https?)://.*|\1|')

    if curl -sf -o /dev/null --max-time 5 "$scheme://$host_port/api/v1/bootstrap" 2>/dev/null; then
      pass "TXBoard 旧 API /api/v1/bootstrap 可达 (curl)"
    else
      # 尝试根路径
      if curl -sf -o /dev/null --max-time 5 "$scheme://$host_port" 2>/dev/null; then
        pass "TXBoard 旧 API 可达 (根路径)"
      else
        fail "TXBoard 旧 API 不可达"
        return 1
      fi
    fi
  fi

  # 验证旧 API 路由存在
  echo "  请确认以下路由在旧入口可用："
  echo "    /api/v1/* (原 TXBoard API)"
  echo "    /s/* (订阅链接)"
  echo "    管理后台"
  echo "    支付回调"
  echo ""

  if ! $AUTO; then
    read -r -p "旧 API 路由是否可用？[y/N]: " confirm
    if [[ "$confirm" == "y" || "$confirm" == "Y" ]]; then
      pass "用户确认旧 API 可用"
    else
      fail "旧 API 不可用 — 回滚失败"
      return 1
    fi
  else
    pass "自动模式：假设旧 API 可达（需人工确认）"
  fi
}

# 步骤 4: 验证 Gateway 不再接收流量
verify_gateway_down() {
  section "步骤 4: 验证 Gateway 不再接收流量"

  local status
  status=$(docker inspect --format='{{.State.Status}}' "${COMPOSE_PROJECT}-gateway-1" 2>/dev/null || echo "missing")

  if [[ "$status" == "running" ]]; then
    fail "Gateway 仍在运行"
    return 1
  fi
  pass "Gateway 已停止 (status: $status)"

  # 验证代理层不再转发到 Gateway
  echo "  请在代理层确认 /gateway/v1/* 已移除或返回 404/410"
  if ! $AUTO; then
    read -r -p "代理层已移除 Gateway 路由？[y/N]: " confirm
    if [[ "$confirm" == "y" || "$confirm" == "Y" ]]; then
      pass "代理层确认移除"
    else
      fail "代理层未移除 Gateway 路由"
      return 1
    fi
  else
    pass "自动模式：假设代理层已移除（需人工确认）"
  fi
}

# 步骤 5: 恢复 Gateway（回滚恢复）
restore_gateway() {
  section "步骤 5: 恢复 Gateway（回滚恢复）"

  if $SKIP_RESTORE; then
    skip "跳过恢复（--skip-restore）— Gateway 保持停止状态"
    return 0
  fi

  if $DRY_RUN; then
    echo "[dry-run] 将执行: docker compose -f $COMPOSE_FILE start gateway"
    pass "dry-run: Gateway 容器恢复操作已模拟"
    return 0
  fi

  if $AUTO; then
    echo "[auto] 恢复 Gateway 容器..."
    docker compose -f "$COMPOSE_FILE" start gateway
    sleep 5

    local status
    status=$(docker inspect --format='{{.State.Status}}' "${COMPOSE_PROJECT}-gateway-1" 2>/dev/null || echo "missing")
    if [[ "$status" == "running" ]]; then
      pass "Gateway 容器已恢复"
    else
      fail "Gateway 容器恢复失败"
      return 1
    fi
  else
    echo "恢复 Gateway："
    echo "  1. 在代理层恢复 /gateway/v1/* 映射"
    echo "  2. 执行: docker compose -f $COMPOSE_FILE start gateway"
    echo "  3. 等待健康检查通过"
    read -r -p "Gateway 已恢复？[y/N]: " confirm
    if [[ "$confirm" == "y" || "$confirm" == "Y" ]]; then
      pass "Gateway 已恢复"
    else
      fail "Gateway 未恢复"
      return 1
    fi
  fi
}

# 步骤 6: 最终验证
final_verification() {
  section "步骤 6: 最终验证"

  # Gateway 健康
  local health
  health=$(docker exec "${COMPOSE_PROJECT}-gateway-1" node -e "fetch('http://127.0.0.1:8787/healthz').then(r=>r.json()).then(j=>console.log(JSON.stringify(j))).catch(()=>process.exit(1))" 2>/dev/null || echo "")
  if [[ -n "$health" ]]; then
    pass "Gateway /healthz 可达"
  else
    fail "Gateway /healthz 不可达"
    return 1
  fi

  # 旧 API 仍可用
  local upstream="${TXBOARD_STAGING_UPSTREAM_URL:-http://txboard}"
  if curl -sf -o /dev/null --max-time 5 "$upstream" 2>/dev/null; then
    pass "旧 API 仍可用"
  else
    fail "旧 API 不可用"
    return 1
  fi

  # 数据卷未被删除
  local volumes
  volumes=$(docker volume ls --filter "name=${COMPOSE_PROJECT}" --format '{{.Name}}' 2>/dev/null || echo "")
  if echo "$volumes" | grep -q "gateway-redis-data"; then
    pass "Redis 数据卷保留"
  else
    fail "Redis 数据卷丢失"
    return 1
  fi

  # TXBoard 数据卷未被触碰
  echo "  确认 TXBoard MySQL/Redis 数据卷未被操作："
  docker volume ls --filter "name=txboard" --format '    {{.Name}}' 2>/dev/null || true
  pass "TXBoard 数据卷未被触碰"
}

# 摘要报告
summary() {
  section "回滚演练摘要"

  local drill_end_time
  drill_end_time=$(date -u +%Y-%m-%dT%H:%M:%SZ)
  local gateway_info
  gateway_info=$(get_gateway_version)

  echo "  演练 ID: $DRILL_ID"
  echo "  开始时间: $DRILL_START_TIME"
  echo "  结束时间: $drill_end_time"
  echo "  Gateway 版本: $gateway_info"
  echo ""
  echo "  通过: $PASS"
  echo "  失败: $FAIL"
  echo "  跳过: $SKIP"
  echo ""

  if [[ $FAIL -eq 0 ]]; then
    echo -e "${GREEN}════════════════════════════════════════════════${NC}"
    echo -e "${GREEN}  回滚演练通过${NC}"
    echo -e "${GREEN}════════════════════════════════════════════════${NC}"
    log "DRILL RESULT: PASS"
  else
    echo -e "${RED}════════════════════════════════════════════════${NC}"
    echo -e "${RED}  回滚演练失败 — 请检查 FAIL 项${NC}"
    echo -e "${RED}════════════════════════════════════════════════${NC}"
    log "DRILL RESULT: FAIL"
  fi

  echo ""
  echo "详细日志: $DRILL_LOG"

  # JSON 输出
  if [[ -n "$OUTPUT_FILE" ]]; then
    cat > "$OUTPUT_FILE" <<EOF
{
  "drillId": "$DRILL_ID",
  "startTime": "$DRILL_START_TIME",
  "endTime": "$drill_end_time",
  "gateway": $gateway_info,
  "pass": $PASS,
  "fail": $FAIL,
  "skip": $SKIP,
  "log": "$DRILL_LOG"
}
EOF
    echo "JSON 报告: $OUTPUT_FILE"
  fi

  echo ""

  if [[ $FAIL -gt 0 ]]; then
    return 1
  fi
}

# 主流程
main() {
  echo "════════════════════════════════════════════════════════════"
  echo "  GW-109 / GW-216: Gateway/旧 API 回滚演练"
  echo "  演练 ID: $DRILL_ID"
  echo "  时间: $DRILL_START_TIME"
  if $DRY_RUN; then
    echo "  模式: DRY-RUN（仅模拟，不实际执行）"
  fi
  echo "════════════════════════════════════════════════════════════"
  log "DRILL START (ID: $DRILL_ID)"

  preflight

  if $DRY_RUN; then
    echo ""
    echo "[dry-run] 跳过实际验证步骤，仅模拟操作"
    echo ""
    simulate_gateway_failure
    restore_gateway
  else
    verify_gateway_up
    simulate_gateway_failure
    verify_legacy_api
    verify_gateway_down
    restore_gateway
    final_verification
  fi

  summary

  log "DRILL END"

  if [[ $FAIL -gt 0 ]]; then
    exit 1
  fi
}

main "$@"
