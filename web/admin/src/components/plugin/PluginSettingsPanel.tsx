import { useMutation, useQuery } from '@tanstack/react-query'
import { Save } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { toast } from 'sonner'
import {
  getPluginConfig,
  updatePluginConfig,
  type PluginItem,
} from '../../api/plugin'
import {
  PluginFieldEditor,
  type NormalizedPluginField,
} from './PluginFieldEditor'

export function PluginSettingsPanel({ plugin }: { plugin: PluginItem }) {
  const query = useQuery({
    queryKey: ['pluginConfig', plugin.code],
    queryFn: () => getPluginConfig(plugin.code),
  })
  const [values, setValues] = useState<Record<string, unknown>>({})

  const fields = useMemo<NormalizedPluginField[]>(() => {
    const source = query.data || plugin.config || {}
    return Object.entries(source).map(([key, field]) => ({ key, field }))
  }, [query.data, plugin.config])

  useEffect(() => {
    const source = query.data || plugin.config || {}
    const next: Record<string, unknown> = {}
    for (const [key, field] of Object.entries(source)) next[key] = field.value
    setValues(next)
  }, [query.data, plugin.config])

  const save = useMutation({
    mutationFn: () => updatePluginConfig(plugin.code, values),
    onSuccess: () => {
      toast.success('插件配置已保存')
      query.refetch()
    },
  })

  function validate() {
    const missing = fields.filter(({ key, field }) =>
      field.required && !field.hidden && (values[key] == null || values[key] === ''),
    )
    if (missing.length) {
      toast.error(`请填写：${missing.map(item => item.field.label || item.key).join('、')}`)
      return false
    }
    return true
  }

  return <div className="card plugin-settings-panel">
    <div className="plugin-panel-head">
      <div><h3>插件设置</h3><p>{plugin.name || plugin.code}</p></div>
    </div>
    {query.isLoading ? <div className="empty-state">加载配置…</div> :
      fields.length ? <>
        <PluginFieldEditor
          fields={fields}
          values={values}
          onChange={(key, value) => setValues(current => ({ ...current, [key]: value }))}
        />
        <div className="card-actions">
          <button className="button primary" disabled={save.isPending} onClick={() => validate() && save.mutate()}>
            <Save size={15}/>{save.isPending ? '保存中…' : '保存配置'}
          </button>
        </div>
      </> : <div className="empty-state">该插件没有可配置项。</div>}
  </div>
}
