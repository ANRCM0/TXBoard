import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { fetchSettings, saveSettings, type Settings } from '../../api/config'

const fields = [
  { key: 'frontend_theme_sidebar', label: '侧边栏主题', options: [{ label: 'Light', value: 'light' }, { label: 'Dark', value: 'dark' }], fallback: 'light' },
  { key: 'frontend_theme_header', label: '顶栏主题', options: [{ label: 'Light', value: 'light' }, { label: 'Dark', value: 'dark' }], fallback: 'dark' },
  { key: 'frontend_theme_color', label: '主题配色', options: [
    { label: 'Default', value: 'default' },
    { label: 'Dark Blue', value: 'darkblue' },
    { label: 'Black', value: 'black' },
    { label: 'Green', value: 'green' },
  ], fallback: 'default' },
  { key: 'frontend_background_url', label: '背景图 URL', fallback: '' },
] as const

export function ThemeAppearanceSettings() {
  const queryClient = useQueryClient()
  const query = useQuery({ queryKey: ['settings', 'frontend'], queryFn: () => fetchSettings('frontend') })
  const [values, setValues] = useState<Settings>({})
  const [changed, setChanged] = useState(false)

  useEffect(() => {
    if (query.data && !changed) setValues(query.data)
  }, [query.data, changed])

  const mutation = useMutation({
    mutationFn: (payload: Settings) => saveSettings(payload),
    onSuccess: (_result, saved) => {
      queryClient.setQueryData(['settings', 'frontend'], (current: Settings | undefined) => ({ ...current, ...saved }))
      setChanged(false)
      toast.success('页面外观已保存')
    },
  })

  function patch(key: string, value: string) {
    setChanged(true)
    setValues(previous => ({ ...previous, [key]: value }))
  }

  function save() {
    // Never send the active theme name: switching themes is a separate, guarded action.
    const payload = Object.fromEntries(fields.map(field =>
      [field.key, values[field.key] ?? field.fallback],
    ))
    mutation.mutate(payload)
  }

  return (
    <section className="card theme-appearance-settings" aria-label="页面外观设置">
      <div className="theme-appearance-heading">
        <div>
          <h2>页面外观</h2>
          <p>原“前端设置”已合并至主题管理；具体主题参数可使用下方主题卡片的“配置”按钮。</p>
        </div>
      </div>
      {query.isPending || query.isError ? (
        <div className="query-feedback" role={query.isError ? 'alert' : 'status'}>
          <span>{query.isError ? '外观设置加载失败，请重试。' : '正在读取页面外观设置…'}</span>
          {query.isError && <button className="button" onClick={() => query.refetch()}>重试</button>}
        </div>
      ) : (
        <>
          <div className="config-form-fields theme-appearance-fields">
            {fields.map(field => (
              <label className="config-field" key={field.key}>
                <span>{field.label}</span>
                {'options' in field && field.options ? (
                  <select
                    value={String(values[field.key] ?? field.fallback)}
                    onChange={event => patch(field.key, event.target.value)}
                  >
                    {field.options.map(option => <option value={option.value} key={option.value}>{option.label}</option>)}
                  </select>
                ) : (
                  <input
                    type="url"
                    placeholder="https://..."
                    value={String(values[field.key] ?? '')}
                    onChange={event => patch(field.key, event.target.value)}
                  />
                )}
              </label>
            ))}
          </div>
          <div className="theme-appearance-actions">
            {mutation.isError && <span role="alert">外观保存失败，修改仍保留，可重试。</span>}
            <button className="button primary" disabled={!changed || mutation.isPending} onClick={save}>
              {mutation.isPending ? '保存中…' : '保存外观'}
            </button>
          </div>
        </>
      )}
    </section>
  )
}
