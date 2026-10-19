import { randomUUID } from 'node:crypto'
import { fileURLToPath } from 'node:url'
import { createServer } from 'vite'
import { coreOperations } from '@txboard/core-sdk'
import { managementFixture } from './lib/admin-management-fixture.mjs'
import { workflowFixture } from './lib/admin-workflows-fixture.mjs'

// Local, explicit preview entry point. No middleware is installed in the normal
// dev command or production build, and no request is forwarded to Core.
const port = Number(process.env.TXBOARD_PREVIEW_PORT || 3001)
const email = 'admin@example.test', password = 'preview123456'
const token = 'Bearer local-preview-only'
const stamp = 1792368000, iso = '2026-10-19T00:00:00Z'

// Reuse the contract-checked read fixtures without launching a test browser.
// This small adapter implements only the route methods those fixtures use.
async function fixtureAdapter(factory) {
  const handlers = []
  const context = {
    async route(_pattern, handler) { handlers.push(handler) },
    async newPage() { return { setDefaultTimeout() {}, on() {} } },
  }
  const fixture = await factory({ async newContext() { return context } })
  return { handlers, state: fixture.state }
}
const management = await fixtureAdapter(managementFixture)
const workflows = await fixtureAdapter(workflowFixture)
const state = management.state
state.node.name = '东京 · 标准线路'
state.node.u = '12884901888'; state.node.d = '55834574848'
state.node.machine_id = state.machine.id
state.detailNode.name = state.node.name
state.detailNode.u = state.node.u; state.detailNode.d = state.node.d
state.machine.name = 'Tokyo Edge 01'
Object.assign(state.subscription, { quota_bytes: '214748364800', consumed_bytes: '68719476736',
  remaining_bytes: '146029888064', upload_bytes: state.node.u, download_bytes: state.node.d })
state.group.name = '标准订阅组'
for (const revision of [state.originalRevision, state.currentRevision]) {
  revision.terms.name = '标准套餐 · 200 GB'
  revision.terms.quota_gib = 200
  revision.terms.prices_minor.monthly = '2900'
}
state.offers[0].name = state.currentRevision.terms.name
state.offers[0].price_minor = '2900'
state.settings.values.app_name = 'TXBoard 演示站点'
workflows.state.ticket.subject = '客户端连接与订阅更新咨询'
workflows.state.coupon.name = '新用户体验优惠'
workflows.state.gift.amount_minor = '5000'
workflows.state.batch.amount_minor = '5000'
workflows.state.batch.total_minor = '5000'
workflows.state.batch.label = '秋季活动礼品卡'

const accounts = ['alice', 'bob', 'carol', 'david', 'emma', 'frank', 'grace', 'henry'].map((name, i) => ({
  id: String(12 + i), email: name + '@example.test', banned: i === 5, is_admin: false,
  created_at: stamp - (i + 1) * 86400, last_login_at: stamp - i * 3600,
}))
const previewNow = new Date()
const previewToday = Date.UTC(previewNow.getUTCFullYear(), previewNow.getUTCMonth(), previewNow.getUTCDate())
let orderIndex = 0
const orders = [12, 8, 13, 16, 9, 11, 7, 10, 14, 8, 12, 5, 9, 6].flatMap((count, day) =>
  Array.from({ length: count }, (_, offset) => {
    const i = orderIndex++, status = [3, 0, 3, 1, 2, 3, 4, 3][i % 8]
    const created = new Date(Math.min(previewNow.getTime(), previewToday - day * 86400000 + (count - offset - 1) * 1800000)).toISOString()
    return { id: String(101 + i), user_id: accounts[i % accounts.length].id,
      trade_no: 'DEMO' + String(i + 1).padStart(8, '0'), plan_id: state.planId, period: 'monthly',
      type: 1, status, amount_minor: '2900', currency: 'CNY',
      plan: { id: state.planId, name: '标准套餐 · 200 GB' }, created_at: created,
      paid_at: [1, 3].includes(status) ? created : null, purchase_terms_version: 'tx-core.purchase-terms/v2' }
  }))
const nodes = ['东京 · 标准线路', '香港 · 优选线路', '新加坡 · 标准线路', '洛杉矶 · 备用线路', '法兰克福 · 标准线路'].map((name, i) => ({
  ...state.node, id: String(BigInt(state.node.id) + BigInt(i)), name,
  host: '192.0.2.' + (i + 1), u: String(BigInt([12, 28, 16, 4, 9][i]) * 1073741824n),
  d: String(BigInt([52, 95, 66, 18, 38][i]) * 1073741824n), enabled: i !== 3,
}))

function previewMiddleware(req, res) {
  const path = new URL(req.url, 'http://127.0.0.1').pathname
  if (path === '/admin') {
    const search = new URL(req.url, 'http://127.0.0.1').search
    res.writeHead(302, { Location: '/admin/' + search }); res.end()
    return true
  }
  if (!path.startsWith('/gateway/')) return false
  const requestId = randomUUID()
  const meta = { version: '1', requestId, coreRequestId: requestId, coreMeta: {} }
  const json = (status, body) => {
    res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store',
      'X-TXBoard-Preview': 'synthetic-read-only' })
    res.end(JSON.stringify(body))
  }
  const fail = (status, code) => json(status, { ok: false, error: { code, message: code },
    meta: { version: '1', requestId } })
  if (path === '/gateway/v1/bootstrap') {
    json(200, { ok: true, data: { security: { captcha: { enabled: false, type: null, siteKey: null } } }, meta })
    return true
  }
  let body = '', bytes = 0
  req.on('data', chunk => {
    bytes += chunk.length
    if (bytes <= 262144) body += chunk
  })
  req.on('end', async () => {
    try {
      if (bytes > 262144) return fail(413, 'PREVIEW_REQUEST_TOO_LARGE')
      const operation = path.split('/operations/')[1]
      if (!operation || !Object.hasOwn(coreOperations, operation)) return fail(404, 'PREVIEW_OPERATION_UNAVAILABLE')
      const spec = coreOperations[operation]
      const input = spec.input.parse(JSON.parse(body).input)
      const send = (data, coreMeta = {}) => {
        spec.output.parse(data); spec.meta.parse(coreMeta)
        json(200, { ok: true, data, meta: { ...meta, coreMeta } })
      }
      const list = rows => {
        const page = input.page ?? 1, perPage = input.per_page ?? 20
        send(rows.slice((page - 1) * perPage, page * perPage),
          { page, per_page: perPage, total: rows.length, last_page: Math.max(1, Math.ceil(rows.length / perPage)) })
      }
      if (operation === 'identity.admin-session.create') {
        if (input.email !== email || input.password !== password) return fail(401, 'PREVIEW_LOGIN_REQUIRED')
        return send({ auth_data: token, is_admin: true, secure_path: 'ci_admin' })
      }
      if (req.headers.authorization !== token) return fail(401, 'PREVIEW_LOGIN_REQUIRED')
      if (operation === 'identity.session.end') return send({ ok: true })
      if (spec.idempotency !== 'read') return fail(403, 'PREVIEW_READ_ONLY')
      if (operation === 'network.nodes.list') return list(nodes)
      if (operation === 'network.nodes.traffic-history.get') {
        const startsAt = previewToday - (input.days - 1) * 86400000
        return send({ time_zone: 'UTC', basis: 'settled-raw-usage/v1', bucket: 'day',
          starts_at: startsAt / 1000, ends_at: Math.floor(previewNow.getTime() / 1000),
          nodes: nodes.filter(row => input.node_ids.includes(row.id)).map((node, index) => ({
            id: node.id, name: node.name,
            points: Array.from({ length: input.days }, (_, day) => ({
              date: new Date(startsAt + day * 86400000).toISOString().slice(0, 10),
              total_bytes: String(BigInt(8 + (day * 7 + index * 11) % 23) * BigInt(index + 1) * 1000000000n + BigInt(13 + (day * 17 + index * 19) % 86) * 10000000n),
            })),
          })),
        })
      }
      if (operation === 'network.nodes.get') {
        const node = nodes.find(row => row.id === input.node_id)
        return node ? send({ ...state.detailNode, ...node }) : fail(404, 'PREVIEW_RECORD_NOT_FOUND')
      }
      if (operation === 'identity.accounts.list') return list(accounts.filter(row =>
        (!input.email || row.email.includes(input.email)) && (input.banned === undefined || row.banned === input.banned)))
      if (operation === 'identity.accounts.get') {
        const account = accounts.find(row => row.id === input.user_id)
        if (!account) return fail(404, 'PREVIEW_RECORD_NOT_FOUND')
        return send({ ...account, credential_status: 'provider_managed',
          subscription: { ...state.subscription, user_id: account.id } })
      }
      if (operation === 'commerce.admin-orders.list') return list(orders.filter(row =>
        (!input.user_id || row.user_id === input.user_id) && (!input.trade_no || row.trade_no.includes(input.trade_no))
        && (input.status === undefined || row.status === input.status)))
      if (operation === 'commerce.admin-orders.get') {
        const order = orders.find(row => row.trade_no === input.trade_no)
        return order ? send(order) : fail(404, 'PREVIEW_RECORD_NOT_FOUND')
      }
      if (operation === 'commerce.admin-orders.events.list') {
        const order = orders.find(row => row.trade_no === input.trade_no)
        if (!order) return fail(404, 'PREVIEW_RECORD_NOT_FOUND')
        const events = [
          { sequence: '1', type: 'created', status: 0, occurred_at: order.created_at },
          ...([1, 3].includes(order.status) ? [{ sequence: '2', type: 'payment_accepted', status: 1, occurred_at: order.paid_at }] : []),
          ...(order.status === 3 ? [{ sequence: '3', type: 'fulfilled', status: 3, occurred_at: order.paid_at }] : []),
          ...(order.status === 2 ? [{ sequence: '2', type: 'cancelled', status: 2, occurred_at: order.created_at }] : []),
        ]
        return send(events.filter(row => BigInt(row.sequence) > BigInt(input.after_sequence ?? '0')),
          { next_after_sequence: events.at(-1).sequence, has_more: false, history_complete: true })
      }
      if (operation === 'commerce.admin-orders.audit.list') return send([], { next_after_id: '0', has_more: false })
      if (operation === 'identity.sessions.list') return send([
        { id: '1', name: '本地预览 · Chrome', abilities: ['admin'], created_at: iso,
          last_used_at: iso, updated_at: iso, expires_at: null },
      ], { has_more: false })
      const workflow = /^(?:support\.|content\.|billing\.(?:coupons\.|admin-gift-|admin-commissions\.))/.test(operation)
      const fixture = workflow ? workflows : management
      const request = {
        url: () => 'http://127.0.0.1' + req.url,
        method: () => req.method,
        postDataJSON: () => ({ input }),
        headers: () => ({ ...req.headers, authorization: workflow ? 'Bearer 1|workflow-fixture' : 'Bearer 1|catalog-network-fixture',
          'x-core-admin-path': 'ci_admin' }),
      }
      async function dispatch(index) {
        if (index < 0) return fail(404, 'PREVIEW_OPERATION_UNAVAILABLE')
        await fixture.handlers[index]({ request: () => request,
          fallback: () => dispatch(index - 1), abort: () => fail(503, 'PREVIEW_UNAVAILABLE'),
          async fulfill({ status, body: payload }) {
            const envelope = JSON.parse(payload)
            if (envelope.ok && operation.startsWith('content.')) {
              const items = Array.isArray(envelope.data) ? envelope.data : [envelope.data]
              for (const item of items) {
                item.title = operation.includes('knowledge') ? '如何导入订阅与更新节点' : '服务维护与线路更新公告'
                if ('content' in item) item.content = '这是本地预览中的测试公告，可查看排版与详情'
                if ('body' in item) item.body = '打开客户端，导入订阅地址后更新节点列表'
              }
            }
            json(status, envelope)
          },
        })
      }
      await dispatch(fixture.handlers.length - 1)
    } catch (error) {
      console.error('Preview operation failed:', path.split('/').at(-1), error.message)
      if (!res.headersSent) fail(500, 'PREVIEW_DATA_ERROR')
    }
  })
  return true
}

const root = fileURLToPath(new URL('../web/admin/', import.meta.url))
process.chdir(root)
const server = await createServer({ root, configFile: root + '/vite.config.ts',
  optimizeDeps: { force: true },
  server: { host: '127.0.0.1', port, strictPort: true, open: false },
  plugins: [{ name: 'txboard-local-preview',
    configureServer(server) {
      server.middlewares.use((req, res, next) => { if (!previewMiddleware(req, res)) next() })
    },
    transformIndexHtml() {
      return [{ tag: 'aside', attrs: { id: 'txboard-preview-label',
        style: 'position:fixed;right:16px;bottom:14px;z-index:2147483000;padding:10px 14px;border:1px solid #c8d5f5;border-radius:12px;background:#edf3ff;color:#253a68;font:12px/1.6 system-ui;box-shadow:0 4px 18px #10204018' },
      children: '<strong>测试数据 · 本地只读预览</strong><br>admin@example.test / preview123456', injectTo: 'body' }]
    },
  }],
})
await server.listen()
server.printUrls()
console.log('Synthetic read-only preview login:', email, '/', password)
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, async () => {
  await server.close(); process.exit(0)
})
