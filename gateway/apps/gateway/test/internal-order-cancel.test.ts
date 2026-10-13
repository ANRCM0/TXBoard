import { EventEmitter } from 'node:events'
import type { X509Certificate } from 'node:crypto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { GatewayConfig } from '../src/config/env.js'
import { loadConfig } from '../src/config/env.js'
import { internalUserRequest } from '../src/services/internal-user-client.js'
import { createGatewayApp } from '../src/app.js'

const mock = vi.hoisted(() => ({ request: vi.fn() }))
vi.mock('node:https', () => ({ default: { request: mock.request } }))
const config: GatewayConfig = { ...loadConfig({ TXBOARD_UPSTREAM_URL: 'https://core.test' }), timeoutMs: 5000,
  internalUser: { origin: new URL('https://127.0.0.1:19443'), servername: 'internal-core.local', ca: Buffer.from('ca'),
    crl: Buffer.from('crl'), cert: Buffer.from('user cert'), key: Buffer.from('private fixture key'),
    certificate: { validFrom: new Date(Date.now()-60000).toISOString(), validTo: new Date(Date.now()+3600000).toISOString() } as X509Certificate } }
const token = 'Bearer real-actor-credential'
const profile = { id: '1', email: 'user@example.test', plan_id: null, balance_minor: '0', commission_balance_minor: '0',
  expired_at: null, telegram_id: null, traffic: { upload_bytes: '0', download_bytes: '0', limit_bytes: '0' } }
const envelope = { data: profile, request_id: '00000000-0000-4000-8000-000000000000', meta: { current_only: true, slice: 'internal-readonly/2' } }
let req: EventEmitter & { destroy: ReturnType<typeof vi.fn>; end: ReturnType<typeof vi.fn> }
let reply: EventEmitter & { destroy: ReturnType<typeof vi.fn>; statusCode: number; headers: Record<string,string>; complete: boolean }
let callback: (reply: unknown) => void
beforeEach(() => {
  vi.useFakeTimers(); mock.request.mockReset()
  req = Object.assign(new EventEmitter(), { destroy: vi.fn(), end: vi.fn() })
  reply = Object.assign(new EventEmitter(), { destroy: vi.fn(), statusCode: 200, headers: { 'content-type': 'application/json' }, complete: true })
  mock.request.mockImplementation((_options, cb) => { callback = cb; return req })
})
afterEach(() => vi.useRealTimers())
function respond(payload: unknown = envelope) { callback(reply); reply.emit('data', Buffer.from(JSON.stringify(payload))); reply.emit('end') }
const enabled: GatewayConfig = { ...config, internalOrderCancelEnabled: true, internalOrderCancelEnvironment: 'testing' }
function cancelReply(data: unknown = { ok: true }) {
  const body = JSON.parse(req.end.mock.calls[0]![0].toString())
  reply.headers['x-request-id'] = body.request_id
  respond({ data, request_id: body.request_id, meta: { current_only: true, slice: 'internal-order-cancel/1' } })
}
describe('isolated native cancellation', () => {
  it('posts only v1 fixed cancellation with current Bearer and closed trade input', async () => {
    const pending=internalUserRequest(enabled,'userOrderCancel',{auth:token,tradeNo:'T-1',body:{}})
    const options=mock.request.mock.calls[0]![0]
    expect(options.path).toBe('/txapi/internal/v1/operations/user.order.cancel');expect(options.headers.authorization).toBe(token)
    expect(JSON.parse(req.end.mock.calls[0]![0].toString())).toMatchObject({contract_version:'1',input:{trade_no:'T-1'}})
    cancelReply();await expect(pending).resolves.toEqual({ok:true});expect(mock.request).toHaveBeenCalledOnce()
  })
  it.each([undefined,false,'true',1])('requires strict independent cancellation gate %s before transport',async enabledFlag=>{
    await expect(internalUserRequest({...enabled,internalOrderCancelEnabled:enabledFlag as boolean},'userOrderCancel',{auth:token,tradeNo:'T-1'})).rejects.toMatchObject({status:404})
    expect(mock.request).not.toHaveBeenCalled()
  })
  it('rejects production or absent environment and native-only runtime at boot',async()=>{
    expect(()=>createGatewayApp({...enabled,internalOrderCancelEnvironment:undefined})).toThrow(/local\/testing/)
    expect(()=>createGatewayApp({...enabled,internalUser:undefined})).toThrow(/mTLS/)
    await expect(internalUserRequest({...enabled,internalOrderCancelEnvironment:'production' as 'testing'},'userOrderCancel',{auth:token,tradeNo:'T-1'})).rejects.toMatchObject({status:404})
    expect(mock.request).not.toHaveBeenCalled()
  })
  it.each(['','../private','bad\n','x'.repeat(129)])('rejects invalid order identifier %s before transport',async tradeNo=>{
    await expect(internalUserRequest(enabled,'userOrderCancel',{auth:token,tradeNo})).rejects.toMatchObject({status:400});expect(mock.request).not.toHaveBeenCalled()
  })
  it('refuses caller owner/body fields and invalid current Bearer',async()=>{
    await expect(internalUserRequest(enabled,'userOrderCancel',{auth:token,tradeNo:'T-1',body:{owner_id:'1'}})).rejects.toMatchObject({status:400})
    await expect(internalUserRequest(enabled,'userOrderCancel',{tradeNo:'T-1'})).rejects.toMatchObject({status:401});expect(mock.request).not.toHaveBeenCalled()
  })
  it.each([409,503,504])('retains status %s after one attempt without refund repair',async status=>{
    const pending=internalUserRequest(enabled,'userOrderCancel',{auth:token,tradeNo:'T-1'})
    const assertion=expect(pending).rejects.toMatchObject({status})
    reply.statusCode=status;respond({error:{code:'ORDER_CONFLICT',message:'private hidden'},request_id:envelope.request_id});await assertion
    expect(mock.request).toHaveBeenCalledOnce();expect(vi.getTimerCount()).toBe(0)
  })
  it('rejects false or unclosed receipts and mismatched request identity',async()=>{
    for(const data of [{ok:false},{ok:true,trade_no:'private'},{ok:1}]) {
      const pending=internalUserRequest(enabled,'userOrderCancel',{auth:token,tradeNo:'T-1'});const assertion=expect(pending).rejects.toMatchObject({status:502});cancelReply(data);await assertion
    }
    const pending=internalUserRequest(enabled,'userOrderCancel',{auth:token,tradeNo:'T-1'});const assertion=expect(pending).rejects.toMatchObject({status:502})
    respond({data:{ok:true},request_id:envelope.request_id,meta:{current_only:true,slice:'internal-order-cancel/1'}});await assertion
  })
  it('actual legacy route requires flag Bearer closed empty body and no query; default makes no upstream call',async()=>{
    const native=vi.fn();const off=createGatewayApp(config,native)
    expect((await off.request('/gateway/v1/orders/T-1/cancel',{method:'POST',headers:{Authorization:token,'Content-Type':'application/json'},body:'{}'})).status).toBe(405)
    expect(native).not.toHaveBeenCalled();expect(mock.request).not.toHaveBeenCalled()
    const app=createGatewayApp(enabled,native)
    for(const [url,body,status] of [['/gateway/v1/orders/T-1/cancel?owner_id=1','{}',400],['/gateway/v1/orders/T-1/cancel','{"owner_id":"1"}',400]] as const) {
      expect((await app.request(url,{method:'POST',headers:{Authorization:token,'Content-Type':'application/json'},body})).status).toBe(status)
    }
    expect((await app.request('/gateway/v1/orders/T-1/cancel',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'})).status).toBe(401)
    expect(mock.request).not.toHaveBeenCalled()
    const pending=app.request('/gateway/v1/orders/T-1/cancel',{method:'POST',headers:{Authorization:token,'Content-Type':'application/json'},body:'{}'})
    await vi.waitFor(()=>expect(mock.request).toHaveBeenCalledOnce());cancelReply();const response=await pending
    expect(response.status).toBe(200);expect(await response.json()).toMatchObject({ok:true,data:{ok:true}});expect(native).not.toHaveBeenCalled()
  })
})
