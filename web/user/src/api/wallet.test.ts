import type { AxiosRequestConfig } from 'axios'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { api, nativeApi, saveAuthData } from './client'
import {
  checkoutRecharge, createRecharge, fetchRecharge, fetchRechargeHistory,
  getWalletBalance, moneyToMinor,
} from './wallet'

const key='e2b01300-9d18-420d-a0d6-f213147d0003'
const item={
  trade_no:'WR7C3EEFA1029930', payment_method_id:3,
  amount_minor:1000, fee_minor:130, total_minor:1130,
  status:0, paid_at:null, created_at:1791500000,
}
const nativeAdapter=nativeApi.defaults.adapter
const legacyAdapter=api.defaults.adapter
let calls:AxiosRequestConfig[]=[]
let mock:(config:AxiosRequestConfig)=>unknown

beforeEach(()=>{
  calls=[]
  localStorage.clear()
  saveAuthData('Bearer wallet-session')
  mock=()=>({data:item,request_id:'wallet-request'})
  nativeApi.defaults.adapter=async config=>{
    calls.push(config)
    return {data:mock(config),status:200,statusText:'OK',headers:{},config} as never
  }
  api.defaults.adapter=async()=>{throw new Error('Wallet must not use V1 billing')}
})
afterEach(()=>{
  nativeApi.defaults.adapter=nativeAdapter
  api.defaults.adapter=legacyAdapter
  localStorage.clear()
})

describe('TXBoard user wallet top-up contracts',()=>{
  it('converts CNY to integer minor units without floating arithmetic',()=>{
    expect(moneyToMinor('10.29')).toBe(1029)
    expect(()=>moneyToMinor('0.99')).toThrow('单次充值金额')
    expect(()=>moneyToMinor('9.999')).toThrow()
    expect(()=>moneyToMinor('5000.01')).toThrow()
    expect(()=>moneyToMinor('-10')).toThrow()
    expect(()=>moneyToMinor('10abc')).toThrow()
  })

  it('posts amount as cents with explicit retry-safe key and bearer authorization',async()=>{
    const created=await createRecharge(1000,3,key)
    expect(created.trade_no).toBe(item.trade_no)
    expect(calls[0].baseURL).toBe('/txapi')
    expect(calls[0].url).toBe('/billing/recharges')
    expect(calls[0].method).toBe('post')
    expect(JSON.parse(String(calls[0].data))).toEqual({
      amount_minor:1000,payment_method_id:3,
    })
    expect(String((calls[0].headers as Record<string,string>).Authorization))
      .toBe('Bearer wallet-session')
    expect(String((calls[0].headers as Record<string,string>)['Idempotency-Key'])).toBe(key)
  })

  it('does not issue a call for invalid recharge requests',async()=>{
    await expect(createRecharge(1,3,key)).rejects.toThrow('Invalid wallet recharge')
    await expect(createRecharge(1000,0,key)).rejects.toThrow('Invalid wallet recharge')
    await expect(createRecharge(1000,3,'invalid')).rejects.toThrow('Invalid wallet recharge')
    expect(calls).toHaveLength(0)
  })

  it('uses native owner-scoped status, wallet and bounded history',async()=>{
    mock=config=>{
      if(config.url==='/billing/wallet')return {
        data:{balance_minor:500,commission_balance_minor:0},request_id:'balance',
      }
      if(config.url==='/billing/recharges')return {
        data:[item],request_id:'history',
        meta:{page:2,per_page:20,total:21,last_page:2},
      }
      return {data:item,request_id:'detail'}
    }
    expect((await getWalletBalance()).balance_minor).toBe(500)
    const page=await fetchRechargeHistory(2)
    expect(page.total).toBe(21)
    expect(page.page).toBe(2)
    expect(calls[1].params).toEqual({page:2,per_page:20})
    expect((await fetchRecharge(item.trade_no)).status).toBe(0)
    expect(calls[2].url).toBe('/billing/recharges/'+item.trade_no)
  })

  it('accepts existing checkout presentation types without pretending credit occurred',async()=>{
    mock=()=>({data:{type:1,data:'https://payment.invalid/submit.php'},request_id:'checkout'})
    const result=await checkoutRecharge(item.trade_no)
    expect(result.type).toBe(1)
    expect(calls[0].url).toBe('/billing/recharges/'+item.trade_no+'/checkout')
    expect(calls[0].method).toBe('post')
  })

  it('fails closed on missing pagination and invalid status DTOs',async()=>{
    mock=()=>({data:[item],request_id:'missing-meta'})
    await expect(fetchRechargeHistory()).rejects.toThrow('Invalid TXAPI recharge history')
    mock=()=>({data:{...item,status:99},request_id:'wrong-status'})
    await expect(fetchRecharge(item.trade_no)).rejects.toThrow('Invalid TXAPI recharge state')
  })
})
