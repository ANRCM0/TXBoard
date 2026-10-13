import { describe, expect, it, vi } from 'vitest'
import { createTXBoardClient } from '../src/index.js'
const success=(data:unknown)=>Response.json({ok:true,data,meta:{version:'1',requestId:'fixture-id'}})
describe('isolated order cancellation SDK',()=>{
  it('posts closed empty body with current Bearer once',async()=>{
    const fetcher=vi.fn(async (_url: RequestInfo | URL, _init?: RequestInit)=>success({ok:true}));const sdk=createTXBoardClient({getToken:()=> 'Bearer private-current',fetchImpl:fetcher as typeof fetch})
    await expect(sdk.orders.cancel('T-1')).resolves.toEqual({ok:true});expect(fetcher).toHaveBeenCalledOnce()
    expect(fetcher.mock.calls[0]![0]).toBe('/gateway/v1/orders/T-1/cancel')
    expect(fetcher.mock.calls[0]![1]).toMatchObject({method:'POST',body:'{}',credentials:'omit'})
  })
  it.each([409,503,504])('never retries ambiguous cancellation status %s',async status=>{
    const fetcher=vi.fn(async()=>Response.json({ok:false,error:{code:'UPSTREAM_ERROR',message:'refused'},meta:{version:'1',requestId:'fixture-id'}},{status}))
    const sdk=createTXBoardClient({getToken:()=> 'Bearer private',fetchImpl:fetcher as typeof fetch,maxRetries:10,baseRetryDelay:1})
    await expect(sdk.orders.cancel('T-1')).rejects.toMatchObject({status,needsStateConfirmation:true});expect(fetcher).toHaveBeenCalledOnce()
  })
  it.each([401,403,404])('current access or ownership refusal %s stays a clear rejection',async status=>{
    const fetcher=vi.fn(async()=>Response.json({ok:false,error:{code:'UPSTREAM_ERROR',message:'denied'},meta:{version:'1',requestId:'fixture-id'}},{status}))
    const sdk=createTXBoardClient({getToken:()=> 'Bearer private',fetchImpl:fetcher as typeof fetch})
    await expect(sdk.orders.cancel('T-1')).rejects.toMatchObject({status,needsStateConfirmation:false});expect(fetcher).toHaveBeenCalledOnce()
  })
  it('network loss stays unknown and is never replayed',async()=>{
    const fetcher=vi.fn(async()=>{throw new Error('private token and URL')});const sdk=createTXBoardClient({getToken:()=> 'Bearer private',fetchImpl:fetcher as typeof fetch,maxRetries:10})
    await expect(sdk.orders.cancel('T-1')).rejects.toMatchObject({code:'NETWORK_ERROR',needsStateConfirmation:true});expect(fetcher).toHaveBeenCalledOnce()
  })
  it.each(['','../private','bad\n','x'.repeat(129)])('rejects invalid trade %s before fetch',async trade=>{
    const fetcher=vi.fn();const sdk=createTXBoardClient({fetchImpl:fetcher as typeof fetch});await expect(sdk.orders.cancel(trade)).rejects.toMatchObject({code:'VALIDATION_ERROR'});expect(fetcher).not.toHaveBeenCalled()
  })
  it.each([{ok:false},{ok:true,token:'private'},true])('rejects unclosed committed receipt %j as unknown state',async data=>{
    const fetcher=vi.fn(async()=>success(data));const sdk=createTXBoardClient({getToken:()=> 'Bearer private',fetchImpl:fetcher as typeof fetch})
    await expect(sdk.orders.cancel('T-1')).rejects.toMatchObject({code:'INVALID_RESPONSE',needsStateConfirmation:true});expect(fetcher).toHaveBeenCalledOnce()
  })
})
