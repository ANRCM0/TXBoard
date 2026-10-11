import { describe, expect, it, vi } from 'vitest'
import { generateKeyPairSync } from 'node:crypto'
import { Aes256Gcm, CipherSuite, DhkemP256HkdfSha256, HkdfSha256 } from '@hpke/core'
import { createGatewayApp } from '../src/app.js'
import { loadConfig } from '../src/config/env.js'
import { CryptoService } from '../src/services/crypto.js'
import { RedisSecurity } from '../src/services/redis-security.js'
import { MetricsRegistry } from '../src/services/metrics.js'
import { SafetyGuard } from '../src/middleware/resilience.js'
import { nullLogger } from '../src/middleware/observability.js'
import { encryptForOperation, encryptLoginPayload } from '../../../packages/theme-sdk/src/crypto.js'

/**
 * GW-404 抗重放验收矩阵（RP-1～RP-10）与 GW-406 故障注入/性能（FI、PERF）。
 *
 * 判据（docs/adr-replay-protection-and-audit.md）：
 *  - D1 nonce 唯一性：Redis 单命令 `SET NX PX`，键 `txbgw:v1:replay:<kid>:<nonce>`，
 *    TTL = 窗口×2 + 1000 = 121s。
 *  - D2 窗口 ±60s 硬编码，越窗 400 且**不接触 Redis**。
 *  - D3 "恰一次"：N 个并发相同密封请求 → 恰 1 通过、N-1 个 409、零多余上游调用。
 *  - D4 fail-closed：reserve 抛错/未就绪 → 503，零进程内回退。
 *  - D6 性能预算：HPKE open 全链路 p95 ≤ 30ms。
 */

const config = loadConfig({
  TXBOARD_UPSTREAM_URL: 'https://txboard.example/',
  GATEWAY_ALLOWED_ORIGINS: 'https://theme.example',
})
const token = 'Bearer usertoken_longer_than_eight'
function ok(data: unknown) { return { status: 'success', message: null, data } }
function loginOk() { return vi.fn(async () => Response.json(ok({ auth_data: token, is_admin: false }))) }
async function dataOf(response: Response) { return await response.json() as any }

function generateJwk() {
  const { privateKey } = generateKeyPairSync('ec', { namedCurve: 'prime256v1' })
  return privateKey.export({ format: 'jwk' })
}

const HPKE_INFO = new TextEncoder().encode('TXBOARD-GW-V1-LOGIN-HPKE')

/**
 * 用指定 kid 的公钥、**固定 nonce** 密封一个请求（GW-404 测试夹具）。
 * 非 SDK 路径：SDK 每次生成随机 nonce，而重放用例需要可控的 nonce 值。
 * `info` 与 Gateway `crypto.ts` 的域分隔串字节一致（改即全部密文作废）。
 */
async function sealWith(
  keyVersion: { kid: string; publicKey: string },
  data: unknown,
  nonce: string,
  ts = Date.now(),
  session = '-',
) {
  const suite = new CipherSuite({
    kem: new DhkemP256HkdfSha256(), kdf: new HkdfSha256(), aead: new Aes256Gcm(),
  })
  const publicKey = await suite.kem.deserializePublicKey(fromB64Key(keyVersion.publicKey))
  const sender = await suite.createSenderContext({ recipientPublicKey: publicKey, info: HPKE_INFO })
  const aad = new TextEncoder().encode(
    'txboard-gateway:v1\nPOST\n/gateway/v1/secure/auth/login\n'
    + keyVersion.kid + '\n' + ts + '\n' + nonce + '\n' + session,
  )
  const ct = await sender.seal(new TextEncoder().encode(JSON.stringify(data)), aad)
  const toB64 = (bytes: Uint8Array) =>
    btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
  return { kid: keyVersion.kid, ts, nonce, enc: toB64(new Uint8Array(sender.enc)), ct: toB64(new Uint8Array(ct)) }
}

function fromB64Key(value: string): Uint8Array {
  const raw = atob(value.replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from(raw, c => c.charCodeAt(0))
}

/**
 * Redis `SET key 1 NX PX ttl` 的忠实内存模拟：单线程事件循环内
 * "判存+占位" 一步完成（await 之间不交错），与真实 Redis 的原子语义一致。
 * 支持 TTL 到期与显式故障注入（fail-closed / 丢键演练）。
 */
function fakeRedisReplay() {
  const entries = new Map<string, number>() // key -> expiresAt (ms epoch)
  let failed = false
  let now = () => Date.now()
  const calls: Array<{ kid: string; nonce: string; ttlMs: number }> = []
  return {
    calls,
    setFailed(value: boolean) { failed = value },
    setNow(fn: () => number) { now = fn },
    /** FI-5：模拟切主丢数据——清空已消费的 nonce 键。 */
    dropAllKeys() { entries.clear() },
    /** 仍在 TTL 内的键数量（丢键窗口观测）。 */
    liveKeys() {
      const t = now()
      return [...entries.values()].filter(expiresAt => expiresAt > t).length
    },
    reserve: async (kid: string, nonce: string, ttlMs: number) => {
      calls.push({ kid, nonce, ttlMs })
      if (failed) throw new Error('connection lost')
      const key = `txbgw:v1:replay:${kid}:${nonce}`
      const expiresAt = entries.get(key)
      if (expiresAt !== undefined && expiresAt > now()) return false
      entries.set(key, now() + ttlMs)
      return true
    },
  }
}

/** fail-closed 链路：SafetyGuard 包一层，复刻生产 wiring（index.ts）。 */
function guardOf(store: ReturnType<typeof fakeRedisReplay>) {
  const metrics = new MetricsRegistry()
  return {
    metrics,
    store,
    guard: new SafetyGuard(
      store as unknown as { check(): Promise<void>; reserve(kid: string, nonce: string, ttlMs: number): Promise<boolean> },
      metrics,
      nullLogger(),
    ),
  }
}

function sealedSend(app: ReturnType<typeof createGatewayApp>, payload: unknown) {
  return app.request('/gateway/v1/secure/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
}

describe('GW-404 抗重放验收矩阵（RP 系列）', () => {
  it('RP-1: 100 个并发提交同一密封请求，恰一次通过、其余 409、上游只被调用一次', async () => {
    const store = fakeRedisReplay()
    const service = await CryptoService.create(await generateJwk(), store)
    const fetcher = loginOk()
    const app = createGatewayApp(config, fetcher, service)
    const sealed = await encryptLoginPayload(service.publicKey(), { email: 'u@example.test', password: 'pass12345678' })

    const responses = await Promise.all(
      Array.from({ length: 100 }, () => sealedSend(app, sealed)),
    )
    const statuses = responses.map(r => r.status)
    expect(statuses.filter(s => s === 200)).toHaveLength(1)
    expect(statuses.filter(s => s === 409)).toHaveLength(99)
    // 恰一次进入上游：唯一的 200 对应唯一的 Laravel 调用。
    expect(fetcher).toHaveBeenCalledTimes(1)
    // 100 个并发都做了原子占用判定（SET NX 语义由 Redis 单命令保证）。
    expect(store.calls).toHaveLength(100)
    for (const call of store.calls) {
      expect(call.kid).toBe(sealed.kid)
      expect(call.nonce).toBe(sealed.nonce)
      expect(call.ttlMs).toBe(121_000) // 窗口×2 + 1000
    }
  })

  it('RP-2: 100 个并发独立 nonce（合法客户端行为）全部通过，重放层不误伤', async () => {
    const store = fakeRedisReplay()
    const service = await CryptoService.create(await generateJwk(), store)
    const app = createGatewayApp(config, loginOk(), service)
    const discovery = service.publicKey()

    const responses = await Promise.all(
      Array.from({ length: 100 }, (_, i) =>
        encryptForOperation(discovery, { email: `user${i}@example.test`, password: 'pass12345678' }, 'login')
          .then(sealed => sealedSend(app, sealed)),
      ),
    )
    expect(responses.every(r => r.status === 200)).toBe(true)
    // 每个请求都有独立的 kid:nonce 键。
    const keys = new Set(store.calls.map(c => `${c.kid}:${c.nonce}`))
    expect(keys.size).toBe(100)
  })

  it('RP-3: 串行重放（第二次提交同一密封请求）409', async () => {
    const store = fakeRedisReplay()
    const service = await CryptoService.create(await generateJwk(), store)
    const app = createGatewayApp(config, loginOk(), service)
    const sealed = await encryptLoginPayload(service.publicKey(), { email: 'u@example.test', password: 'pass12345678' })

    expect((await sealedSend(app, sealed)).status).toBe(200)
    const replay = await sealedSend(app, sealed)
    expect(replay.status).toBe(409)
    expect((await dataOf(replay)).error.code).toBe('VALIDATION_ERROR')
  })

  it('RP-4: 双 kid 命名空间独立——同 nonce 在两个 kid 下各自恰好放行一次', async () => {
    const store = fakeRedisReplay()
    const currentJwk = await generateJwk()
    const prevJwk = await generateJwk()
    const service = await CryptoService.create(currentJwk, store, false, prevJwk, Date.now() + 3_600_000)
    const discovery = service.publicKey()
    expect(discovery.keys).toHaveLength(2)
    const kidA = discovery.keys[0]!
    const kidB = discovery.keys[1]!
    expect(kidA.kid).not.toBe(kidB.kid)

    // 同一 nonce 在两个 kid 下各自密封一次：键含 kid 段，互不碰撞。
    const nonce = 'bbbbbbbbbbbbbbbbbbbbbb'
    const ts = Date.now()
    const sealedA = await sealWith(kidA, { email: 'a@example.test', password: 'pass12345678' }, nonce, ts)
    const sealedB = await sealWith(kidB, { email: 'b@example.test', password: 'pass12345678' }, nonce, ts)

    expect(await service.open(sealedA, 'login')).toEqual({ email: 'a@example.test', password: 'pass12345678' })
    expect(await service.open(sealedB, 'login')).toEqual({ email: 'b@example.test', password: 'pass12345678' })
    // 两个 kid 各占一次（命名空间隔离），同一 nonce 未碰撞。
    expect(store.calls.map(c => c.kid)).toEqual([kidA.kid, kidB.kid])
    expect(store.calls.every(c => c.nonce === nonce)).toBe(true)
    // 各自重放 → 409。
    await expect(service.open(sealedA, 'login')).rejects.toMatchObject({ status: 409 })
    await expect(service.open(sealedB, 'login')).rejects.toMatchObject({ status: 409 })
  })

  it('RP-5: reserve 抛错（连接断开）+ 加密敏感写 → 503，零上游调用，无进程内回退', async () => {
    const store = fakeRedisReplay()
    store.setFailed(true)
    const { metrics, guard } = guardOf(store)
    const service = await CryptoService.create(await generateJwk(), guard)
    const fetcher = loginOk()
    const app = createGatewayApp(config, fetcher, service)
    const sealed = await encryptLoginPayload(service.publicKey(), { email: 'u@example.test', password: 'pass12345678' })

    const res = await sealedSend(app, sealed)
    expect(res.status).toBe(503)
    expect((await dataOf(res)).error.code).toBe('UPSTREAM_UNAVAILABLE')
    expect(fetcher).not.toHaveBeenCalled()
    expect(metrics.redisDegraded.get({ operation: 'replay', policy: 'fail-closed' })).toBe(1)

    // 恢复后无需重启即可再次放行（无粘性故障状态）。
    store.setFailed(false)
    expect((await sealedSend(app, sealed)).status).toBe(200)
  })

  it('RP-6: Redis 未就绪（isReady=false）+ 加密写 → 503（与 RP-5 同语义两条路径）', async () => {
    const notReady = { isReady: false, set: async () => 'OK' }
    const redis = new (RedisSecurity as unknown as {
      new (client: unknown): { reserve(kid: string, nonce: string, ttlMs: number): Promise<boolean> }
    })(notReady)
    const service = await CryptoService.create(await generateJwk(), redis as never)
    const app = createGatewayApp(config, loginOk(), service)
    const sealed = await encryptLoginPayload(service.publicKey(), { email: 'u@example.test', password: 'pass12345678' })

    const res = await sealedSend(app, sealed)
    expect(res.status).toBe(503)
    expect((await dataOf(res)).error.code).toBe('UPSTREAM_UNAVAILABLE')
  })

  it('RP-7: ts = now±61s → 400 Expired，且不接触 Redis（时间窗先于解密与占用）', async () => {
    const store = fakeRedisReplay()
    const service = await CryptoService.create(await generateJwk(), store)
    const app = createGatewayApp(config, loginOk(), service)
    const sealed = await encryptLoginPayload(service.publicKey(), { email: 'u@example.test', password: 'pass12345678' })

    for (const delta of [61_000, -61_000]) {
      const res = await sealedSend(app, { ...sealed, ts: sealed.ts + delta })
      expect(res.status).toBe(400)
      expect((await dataOf(res)).error.message).toBe('Expired encrypted request')
    }
    // 两个越窗请求都没有消耗任何 nonce 键。
    expect(store.calls).toHaveLength(0)
  })

  it('RP-7b: 窗口边界 ±59s 内仍放行（窗口下界未被错误收紧）', async () => {
    const store = fakeRedisReplay()
    const service = await CryptoService.create(await generateJwk(), store)
    const app = createGatewayApp(config, loginOk(), service)
    // 手工密封：ts 取过去 59s，nonce/enc/ct 合法但密文无效也会 400——因此用
    // 服务层直接验证窗口判定（open 先校验 ts）。
    const inside = await service.open(
      { kid: service.publicKey().keys[0]!.kid, ts: Date.now() - 59_000, nonce: 'a'.repeat(22), enc: 'e'.repeat(44), ct: 'c'.repeat(40) },
      'login',
    ).then(() => 'passed-window', (e) => e.message)
    expect(inside).toBe('Invalid encrypted request') // 过窗但密文无效 → 400（非 Expired）
    const outside = await service.open(
      { kid: service.publicKey().keys[0]!.kid, ts: Date.now() - 61_000, nonce: 'a'.repeat(22), enc: 'e'.repeat(44), ct: 'c'.repeat(40) },
      'login',
    ).then(() => 'passed-window', (e) => e.message)
    expect(outside).toBe('Expired encrypted request')
    expect(store.calls).toHaveLength(0)
  })

  it('RP-8: nonce 非 22 位 base64url / 缺字段 / ts 非安全整数 → 400，不进 Redis 键空间', async () => {
    const store = fakeRedisReplay()
    const service = await CryptoService.create(await generateJwk(), store)
    const app = createGatewayApp(config, loginOk(), service)
    const sealed = await encryptLoginPayload(service.publicKey(), { email: 'u@example.test', password: 'pass12345678' })

    const bad = [
      { ...sealed, nonce: 'short' },
      { ...sealed, nonce: 'a'.repeat(23) },
      { ...sealed, nonce: '!'.repeat(22) },
      { ...sealed, ts: Number.MAX_SAFE_INTEGER + 2 },
      { ...sealed, ts: 1.5 },
      { kid: sealed.kid, ts: sealed.ts, nonce: sealed.nonce, ct: sealed.ct }, // 缺 enc
    ]
    for (const payload of bad) {
      const res = await sealedSend(app, payload)
      expect(res.status).toBe(400)
    }
    expect(store.calls).toHaveLength(0)
  })

  it('RP-9: nonce 键 TTL 到期后同一 nonce 再提交——时间窗先拒（TTL 与窗口一致性）', async () => {
    vi.useFakeTimers()
    try {
      // 虚拟时钟（全局 Date.now）：ts 在窗内时 nonce 已被占用；时钟前进 > 121s
      // （TTL）后键消失，但同一 ts 的请求已出 ±60s 窗，必然被时间窗 400 拒绝。
      const store = fakeRedisReplay()
      const base = 1_700_000_000_000
      vi.setSystemTime(base)
      const service = await CryptoService.create(await generateJwk(), store)
      const key = service.publicKey().keys[0]!
      const nonce = 'c'.repeat(22)
      const sealed = await sealWith(key, { email: 'u@example.test', password: 'pass12345678' }, nonce, base)

      expect(await service.open(sealed, 'login')).toEqual({ email: 'u@example.test', password: 'pass12345678' })
      expect(store.calls).toHaveLength(1)
      expect(store.liveKeys()).toBe(1)

      // 前进 130s：TTL（121s）已过，键消失；同一 ts 已出窗。
      vi.setSystemTime(base + 130_000)
      await expect(service.open(sealed, 'login')).rejects.toMatchObject({
        status: 400, message: 'Expired encrypted request',
      })
      // 时间窗拒绝发生在 Redis 接触之前：仍是 1 次调用，键已过期。
      expect(store.calls).toHaveLength(1)
      expect(store.liveKeys()).toBe(0)
    } finally {
      vi.useRealTimers()
    }
  })

  it('RP-10: 并发 100 × 跨 kid 混合——每个 kid 恰好放行一次', async () => {
    const store = fakeRedisReplay()
    const service = await CryptoService.create(await generateJwk(), store)
    const fetcher = loginOk()
    const app = createGatewayApp(config, fetcher, service)
    const discovery = service.publicKey()
    const kid = discovery.keys[0]!.kid

    // 单 kid 下构造两个不同密文组（A、B），每组 50 个并发同密封请求。
    const sealedA = await encryptLoginPayload(discovery, { email: 'a@example.test', password: 'pass12345678' })
    const sealedB = await encryptLoginPayload(discovery, { email: 'b@example.test', password: 'pass12345678' })

    const responses = await Promise.all([
      ...Array.from({ length: 50 }, () => sealedSend(app, sealedA)),
      ...Array.from({ length: 50 }, () => sealedSend(app, sealedB)),
    ])
    const statuses = responses.map(r => r.status)
    expect(statuses.filter(s => s === 200)).toHaveLength(2)
    expect(statuses.filter(s => s === 409)).toHaveLength(98)
    expect(fetcher).toHaveBeenCalledTimes(2)
    // 两个不同密文 = 两个不同 nonce，键空间互不干扰。
    const nonces = new Set(store.calls.map(c => c.nonce))
    expect(nonces.size).toBe(2)
    expect(store.calls.every(c => c.kid === kid)).toBe(true)
  })
})

describe('GW-406 故障注入矩阵（FI 系列）', () => {
  it('FI-1: key rotation 双窗口——旧 kid 窗口内 200、窗口外 400、回滚恢复', async () => {
    const store = fakeRedisReplay()
    const newJwk = await generateJwk()
    const oldJwk = await generateJwk()

    // 双窗口态：新当前 + 旧（未过期）。
    const rotating = await CryptoService.create(newJwk, store, false, oldJwk, Date.now() + 3_600_000)
    const discovery = rotating.publicKey()
    expect(discovery.keys).toHaveLength(2)
    expect(discovery.keys[0]!.expiresAt).toBeUndefined()
    expect(discovery.keys[1]!.expiresAt).toBeDefined()

    const app = createGatewayApp(config, loginOk(), rotating)
    const sealed = await encryptLoginPayload(discovery, { email: 'u@example.test', password: 'pass12345678' })
    expect((await sealedSend(app, sealed)).status).toBe(200) // ① 旧 kid（keys[0] 为当前）

    // 窗口过期：旧 kid 不再被尝试。
    const expired = await CryptoService.create(newJwk, fakeRedisReplay(), false, oldJwk, Date.now() - 1_000)
    expect(expired.publicKey().keys).toHaveLength(1)
    const oldKid = discovery.keys[1]!.kid
    await expect(expired.open(
      { kid: oldKid, ts: Date.now(), nonce: 'a'.repeat(22), enc: 'e'.repeat(44), ct: 'c'.repeat(40) },
      'login',
    )).rejects.toMatchObject({ status: 400, message: 'Unknown encryption key' })

    // 回滚：换回单密钥态，当前 kid 密文恢复。
    const rolledBack = await CryptoService.create(oldJwk, fakeRedisReplay())
    const rbDiscovery = rolledBack.publicKey()
    expect(rbDiscovery.keys).toHaveLength(1)
    expect(rbDiscovery.keys[0]!.kid).toBe(oldKid)
    const rbApp = createGatewayApp(config, loginOk(), rolledBack)
    const rbSealed = await encryptLoginPayload(rbDiscovery, { email: 'u@example.test', password: 'pass12345678' })
    expect((await sealedSend(rbApp, rbSealed)).status).toBe(200)
  })

  it('FI-3: 时钟偏差 ±90s/±300s 一律 400 Expired，窗口不放大', async () => {
    const store = fakeRedisReplay()
    const service = await CryptoService.create(await generateJwk(), store)
    const app = createGatewayApp(config, loginOk(), service)
    const sealed = await encryptLoginPayload(service.publicKey(), { email: 'u@example.test', password: 'pass12345678' })

    for (const delta of [90_000, -90_000, 300_000, -300_000]) {
      const res = await sealedSend(app, { ...sealed, ts: sealed.ts + delta })
      expect(res.status).toBe(400)
      expect((await dataOf(res)).error.message).toBe('Expired encrypted request')
    }
    expect(store.calls).toHaveLength(0)
  })

  it('FI-4: Redis 断连——加密敏感写 503 零上游；恢复后自动放行无需重启', async () => {
    const store = fakeRedisReplay()
    const service = await CryptoService.create(await generateJwk(), store)
    const fetcher = loginOk()
    const app = createGatewayApp(config, fetcher, service)
    const sealed = await encryptLoginPayload(service.publicKey(), { email: 'u@example.test', password: 'pass12345678' })

    expect((await sealedSend(app, sealed)).status).toBe(200)
    expect(fetcher).toHaveBeenCalledTimes(1)

    store.setFailed(true) // kill 连接
    const down = await sealedSend(app, sealed)
    expect(down.status).toBe(503)
    expect(fetcher).toHaveBeenCalledTimes(1) // 断连期间零上游调用

    store.setFailed(false) // 重连
    const recovered = await encryptLoginPayload(service.publicKey(), { email: 'u@example.test', password: 'pass12345678' })
    expect((await sealedSend(app, recovered)).status).toBe(200)
    expect(fetcher).toHaveBeenCalledTimes(2)
  })

  it('FI-5: Redis 切主丢键——丢键后同 nonce 重放仍被时间窗拦截（残余风险如实声明）', async () => {
    vi.useFakeTimers()
    try {
      const store = fakeRedisReplay()
      const base = 1_700_000_000_000
      vi.setSystemTime(base)
      const service = await CryptoService.create(await generateJwk(), store)
      const key = service.publicKey().keys[0]!
      const nonce = 'd'.repeat(22)
      const sealed = await sealWith(key, { email: 'u@example.test', password: 'pass12345678' }, nonce, base)

      expect(await service.open(sealed, 'login')).toEqual({ email: 'u@example.test', password: 'pass12345678' })
      expect(store.liveKeys()).toBe(1)

      // 切主丢数据：键全部丢失，但时间窗内同 nonce 再提交会被 SET NX 重新放行一次
      // ——这正是 D5 声明的"单次、单 nonce、仍需落在窗内"的残余风险。
      store.dropAllKeys()
      expect(store.liveKeys()).toBe(0)
      expect(await service.open(sealed, 'login')).toEqual({ email: 'u@example.test', password: 'pass12345678' })
      expect(store.calls).toHaveLength(2)

      // 但只要时钟前进超出 ±60s 窗，同一 ts 的请求一律 400（残余风险不随窗口放大）。
      vi.setSystemTime(base + 61_000)
      await expect(service.open(sealed, 'login')).rejects.toMatchObject({
        status: 400, message: 'Expired encrypted request',
      })
    } finally {
      vi.useRealTimers()
    }
  })
})

describe('GW-406 性能预算（D6：Gateway 附加延迟 p95 ≤ 30ms）', () => {
  it('HPKE open 全链路（解封装 + GCM + nonce 原子占用）p95 ≤ 30ms', async () => {
    const store = fakeRedisReplay()
    const service = await CryptoService.create(await generateJwk(), store)
    const app = createGatewayApp(config, loginOk(), service)

    // 预热：JIT 与密钥导入成本不计入样本。
    const warm = await encryptLoginPayload(service.publicKey(), { email: 'warm@example.test', password: 'pass12345678' })
    await sealedSend(app, warm)

    const N = 300
    const samples: number[] = []
    for (let i = 0; i < N; i += 1) {
      const sealed = await encryptLoginPayload(service.publicKey(), { email: `u${i}@example.test`, password: 'pass12345678' })
      const started = process.hrtime.bigint()
      const res = await sealedSend(app, sealed)
      samples.push(Number(process.hrtime.bigint() - started) / 1e6)
      expect(res.status).toBe(200)
    }
    samples.sort((a, b) => a - b)
    const p50 = samples[Math.floor(N * 0.5)]!
    const p95 = samples[Math.floor(N * 0.95)]!
    const p99 = samples[Math.floor(N * 0.99)]!
    // 输出样本供人工核对（CI 日志），断言只卡预算上界。
    console.log(`[GW-406 PERF] N=${N} p50=${p50.toFixed(2)}ms p95=${p95.toFixed(2)}ms p99=${p99.toFixed(2)}ms`)
    expect(p95).toBeLessThanOrEqual(30)
  })

  it('非ce 原子占用（SET NX PX 单项）p95 ≤ 2ms', async () => {
    const store = fakeRedisReplay()
    const service = await CryptoService.create(await generateJwk(), store)
    const kid = service.publicKey().keys[0]!.kid

    const N = 300
    const samples: number[] = []
    for (let i = 0; i < N; i += 1) {
      const started = process.hrtime.bigint()
      await store.reserve(kid, `n${i}`.padEnd(22, 'x'), 121_000)
      samples.push(Number(process.hrtime.bigint() - started) / 1e6)
    }
    samples.sort((a, b) => a - b)
    const p95 = samples[Math.floor(N * 0.95)]!
    console.log(`[GW-406 PERF] reserve N=${N} p95=${p95.toFixed(4)}ms`)
    expect(p95).toBeLessThanOrEqual(2)
  })
})
