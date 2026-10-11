import { Check, ChevronDown, Search } from 'lucide-react'
import { useEffect, useId, useRef, useState } from 'react'
import type { ProtocolFormOption } from '../../api/server'

export function NodePresetPicker({label,value,options,onChange,allowCustom=false}: {
  label:string
  value: string
  options:ProtocolFormOption[]
  onChange:(value:string)=>void
  allowCustom?:boolean
}) {
  const [open,setOpen]=useState(false)
  const [query,setQuery]=useState('')
  const [custom,setCustom]=useState(false)
  const ref=useRef<HTMLDivElement>(null)
  const trigger=useRef<HTMLButtonElement>(null)
  const search=useRef<HTMLInputElement>(null)
  const listId=useId()
  const selected=options.find(item=>String(item.value)===value)
  const isUnknown=Boolean(value) && !selected
  const customMode=allowCustom && (custom || isUnknown)
  const visible=options.filter(item=>(item.label+' '+item.value).toLowerCase().includes(query.trim().toLowerCase()))

  useEffect(()=>{
    if (!open) return
    search.current?.focus()
    const closeOutside=(event:PointerEvent)=>{
      if (!ref.current?.contains(event.target as Node)) setOpen(false)
    }
    const closeOnEscape=(event:KeyboardEvent)=>{
      if (event.key==='Escape' && ref.current?.contains(document.activeElement)) {
        event.preventDefault()
        setOpen(false)
        trigger.current?.focus()
      }
    }
    document.addEventListener('pointerdown',closeOutside)
    document.addEventListener('keydown',closeOnEscape)
    return ()=>{
      document.removeEventListener('pointerdown',closeOutside)
      document.removeEventListener('keydown',closeOnEscape)
    }
  },[open])

  const choose=(item:ProtocolFormOption)=>{
    onChange(String(item.value))
    setCustom(false)
    setOpen(false)
    setQuery('')
    trigger.current?.focus()
  }

  return <div ref={ref} className="node-preset-picker">
    {customMode ? <div className="node-preset-custom">
      <input aria-label={label+'自定义值'} value={value}
        onChange={event=>onChange(event.target.value)} placeholder={'输入自定义'+label}/>
      <button type="button" className="button" onClick={()=>{setCustom(false);setOpen(true);setQuery('')}}>
        选择预设
      </button>
      {isUnknown ? <small>已保留现有自定义配置；更换预设前不会覆盖。</small> : null}
    </div> : <button ref={trigger} type="button" className="node-preset-trigger"
      aria-label={label} aria-haspopup="listbox" aria-expanded={open} aria-controls={open?listId:undefined}
      onClick={()=>{setOpen(!open);setQuery('')}}>
      <span className="node-preset-selected">{selected?.label ?? (value || '请选择'+label)}</span>
      <ChevronDown size={16} aria-hidden="true"/>
    </button>}

    {open ? <div className="node-preset-options" id={listId} role="listbox" aria-label={label+'选项'}>
      <label className="node-preset-search">
        <Search size={15} aria-hidden="true"/>
        <input ref={search} type="search" value={query}
          aria-label={'搜索'+label+'选项'} placeholder="搜索预设选项…"
          onChange={event=>setQuery(event.target.value)}
          onKeyDown={event=>{
            if (event.key==='ArrowDown') {
              event.preventDefault()
              event.currentTarget.closest('.node-preset-options')
                ?.querySelector<HTMLButtonElement>('button[role="option"]')?.focus()
            }
          }}/>
      </label>
      <div className="node-preset-option-list">
        {visible.map(item=><button type="button" role="option"
          className={'node-preset-option'+(String(item.value)===value?' selected':'')}
          aria-selected={String(item.value)===value}
          key={String(item.value)}
          onKeyDown={event=>{
            if (!['ArrowDown','ArrowUp','Home','End'].includes(event.key)) return
            const entries=Array.from(event.currentTarget.parentElement!.querySelectorAll<HTMLButtonElement>('button[role="option"]'))
            const index=entries.indexOf(event.currentTarget)
            const next=event.key==='Home'?0:event.key==='End'?entries.length-1:
              (index+(event.key==='ArrowDown'?1:-1)+entries.length)%entries.length
            event.preventDefault()
            entries[next]?.focus()
          }}
          onClick={()=>choose(item)}>
          <span>{item.label}</span>
          {String(item.value)===value?<Check size={15} aria-hidden="true"/>:null}
        </button>)}
        {!visible.length?<p className="node-preset-no-options">没有匹配的选项</p>:null}
        {allowCustom ? <button type="button" className="node-preset-custom-option"
          onClick={()=>{setCustom(true);setOpen(false);setQuery('')}}>
          自定义其他值…
        </button>:null}
      </div>
    </div>:null}
  </div>
}
