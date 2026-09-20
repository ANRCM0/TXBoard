import { useMutation, useQuery } from '@tanstack/react-query'
import { Monitor, Smartphone } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { toast } from 'sonner'
import { fetchSettings, saveSettings, type Settings } from '../../api/config'
import { ConfigSectionFrame } from '../../components/config/ConfigSectionFrame'

type Platform = {
  key: string
  title: string
  icon: typeof Monitor
  versionKey: string
  urlKey: string
}

const platforms: Platform[] = [
  { key: 'windows', title: 'Windows', icon: Monitor, versionKey: 'windows_version', urlKey: 'windows_download_url' },
  { key: 'macos', title: 'macOS', icon: Monitor, versionKey: 'macos_version', urlKey: 'macos_download_url' },
  { key: 'android', title: 'Android', icon: Smartphone, versionKey: 'android_version', urlKey: 'android_download_url' },
]

export function AppSettingsPage() {
  const query = useQuery({ queryKey: ['settings', 'app'], queryFn: () => fetchSettings('app') })
  const [form, setForm] = useState<Settings>({})
  const timer = useRef<number | undefined>()

  useEffect(() => {
    if (query.data) setForm(query.data)
  }, [query.data])

  const save = useMutation({
    mutationFn: (payload: Settings) => saveSettings(payload),
    onSuccess: () => toast.success('APP 配置已保存'),
  })

  function patch(key: string, value: unknown) {
    setForm(prev => {
      const next = { ...prev, [key]: value }
      window.clearTimeout(timer.current)
      timer.current = window.setTimeout(() => save.mutate(next), 1000)
      return next
    })
  }

  return (
    <ConfigSectionFrame title="APP 设置" description="维护 Windows、macOS 与 Android 客户端发布信息。">
      {query.isLoading ? <p className="config-frame-loading">加载中…</p> : (
        <div className="config-app-platforms">
          {platforms.map(platform => {
            const Icon = platform.icon
            return (
              <section className="config-app-platform" key={platform.key}>
                <div className="config-app-platform-head">
                  <Icon size={20}/>
                  <div><h3>{platform.title}</h3><p>客户端发布信息</p></div>
                </div>
                <label className="config-field">
                  <span>版本号</span>
                  <input value={String(form[platform.versionKey] ?? '')} placeholder="1.0.0" onChange={event => patch(platform.versionKey, event.target.value)} />
                </label>
                <label className="config-field">
                  <span>下载地址</span>
                  <input value={String(form[platform.urlKey] ?? '')} placeholder="https://..." onChange={event => patch(platform.urlKey, event.target.value)} />
                </label>
              </section>
            )
          })}
          <div className="config-autosave">{save.isPending ? '保存中…' : '修改后 1 秒自动保存'}</div>
        </div>
      )}
    </ConfigSectionFrame>
  )
}
