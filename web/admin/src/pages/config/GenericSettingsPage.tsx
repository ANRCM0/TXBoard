import { useMutation, useQuery } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { toast } from 'sonner'
import { fetchSettings, saveSettings } from '../../api/config'
import { ConfigSectionFrame } from '../../components/config/ConfigSectionFrame'
import { JsonEditor } from '../../components/ui/JsonEditor'

export function GenericSettingsPage({
  settingKey,
  title,
  saveMode='blur',
}: {
  settingKey:string
  title:string
  saveMode?:'blur'|'change'
}){
  const query=useQuery({queryKey:['settings',settingKey],queryFn:()=>fetchSettings(settingKey)})
  const [value,setValue]=useState<Record<string,unknown>>({})
  const timer=useRef<number|undefined>()
  const mutation=useMutation({
    mutationFn:(next:Record<string,unknown>)=>saveSettings(next),
    onSuccess:()=>toast.success('已保存'),
  })

  useEffect(()=>{if(query.data)setValue(query.data)},[query.data])

  const commit=(next:Record<string,unknown>)=>{
    setValue(next)
    if(saveMode==='change'){
      window.clearTimeout(timer.current)
      timer.current=window.setTimeout(()=>mutation.mutate(next),1000)
    }
  }

  return (
    <ConfigSectionFrame title={title} description={`动态配置：${settingKey}`}>
      {query.isLoading ? <p className="config-frame-loading">加载中…</p> : (
        <div className="config-form-sections">
          <JsonEditor value={value} onChange={commit}/>
          <div className="config-action-row">
            <span>{mutation.isPending?'保存中…':saveMode==='change'?'1 秒防抖自动保存':'点击保存'}</span>
            {saveMode==='blur' ? <button className="button primary" onClick={()=>mutation.mutate(value)}>保存</button> : null}
          </div>
        </div>
      )}
    </ConfigSectionFrame>
  )
}
