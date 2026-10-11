import { Plus, Trash2 } from 'lucide-react'
import type { NodeRateTimeRange } from '../../api/server'

export function NodeRateSchedule({value,onChange}:{
  value:NodeRateTimeRange[]
  onChange:(ranges:NodeRateTimeRange[])=>void
}) {
  const edit=(index:number,key:keyof NodeRateTimeRange,next:string)=>{
    onChange(value.map((row,i)=>i===index?{
      ...row,[key]:key==='rate'?Number(next):next,
    }:row))
  }
  return <div className="node-rate-schedule">
    <div className="node-editor-label-row"><strong>分时倍率</strong><small>为不同时间段设置倍率，无需填写 JSON</small></div>
    {value.map((row,index)=><div key={index} className="node-rate-row">
      <label>开始时间<input type="time" value={row.start} onChange={event=>edit(index,'start',event.target.value)}/></label>
      <label>结束时间<input type="time" value={row.end} onChange={event=>edit(index,'end',event.target.value)}/></label>
      <label>倍率<input type="number" min={0} step="0.01" value={row.rate} onChange={event=>edit(index,'rate',event.target.value)}/></label>
      <button type="button" className="icon-button" aria-label={'删除第'+(index+1)+'个时间段'}
        onClick={()=>onChange(value.filter((_,i)=>index!==i))}><Trash2 size={16}/></button>
    </div>)}
    <button type="button" className="button node-rate-add"
      onClick={()=>onChange([...value,{start:'08:00',end:'20:00',rate:1}])}>
      <Plus size={15}/>添加时间段
    </button>
    {!value.length ? <p>尚未设置时间段；未匹配的时间使用基础倍率。</p>:null}
  </div>
}
