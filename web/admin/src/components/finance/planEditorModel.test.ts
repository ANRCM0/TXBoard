import {describe, expect, it} from 'vitest'
import {discountedPlanPrices, draftFromPlan, planPayload, validatePlanDraft} from './planEditorModel'
import type {PlanItem} from '../../api/finance'

describe('plan editor contract model',()=>{
  it('preserves the native creation shape and respects explicitly empty optionals',()=>{
    const draft=draftFromPlan()
    draft.name=' Pro 100G '
    draft.prices.monthly='12.49'
    draft.tags='热门, 稳定,'
    expect(validatePlanDraft(draft)).toEqual({})
    expect(planPayload(draft)).toEqual({
      name:'Pro 100G',content:null,group_id:null,transfer_enable:100,
      speed_limit:null,device_limit:null,capacity_limit:null,reset_traffic_method:null,
      prices:{monthly:12.49},tags:['热门','稳定'],
    })
  })
  it('validates inline bad numeric fields and period-specific prices',()=>{
    const draft=draftFromPlan()
    draft.name=' '
    draft.transfer_enable='1.5'
    draft.speed_limit='-1'
    draft.device_limit='1.5'
    draft.capacity_limit='abc'
    draft.prices.yearly='-8'
    draft.prices.monthly='0.004'
    const errors=validatePlanDraft(draft)
    for(const key of ['name','transfer_enable','speed_limit','device_limit','capacity_limit','prices.monthly','prices.yearly']) {
      expect(errors[key],key).toBeTruthy()
    }
    expect(()=>planPayload(draft)).toThrow('套餐表单校验失败')
  })
  it('retains backend enum, pricing and custom legacy settings on edit',()=>{
    const existing={
      id:5,name:'Legacy',transfer_enable:120,group_id:99,reset_traffic_method:9,
      prices:{monthly:10,reset_traffic:2.5,onetime:45},tags:['VIP','固定'],
    } as PlanItem
    const draft=draftFromPlan(existing)
    expect(draft.reset_traffic_method).toBe('9')
    expect(draft.prices.reset_traffic).toBe('2.5')
    expect(planPayload(draft,existing)).toMatchObject({
      id:5,group_id:99,reset_traffic_method:9,
      prices:{monthly:10,reset_traffic:2.5,onetime:45},tags:['VIP','固定'],
    })
    draft.force_update=true
    expect(planPayload(draft,existing).force_update).toBe(true)
    expect(planPayload(draft)).not.toHaveProperty('force_update')
  })
  it('only generates discounts for existing price periods when explicitly invoked',()=>{
    const result=discountedPlanPrices('10')!
    expect(result).toMatchObject({quarterly:'28.50',half_yearly:'54.00',yearly:'102.00'})
    expect(result).not.toHaveProperty('monthly')
    expect(result).not.toHaveProperty('onetime')
    expect(discountedPlanPrices('')).toBeNull()
    expect(discountedPlanPrices('-1')).toBeNull()
  })
})
