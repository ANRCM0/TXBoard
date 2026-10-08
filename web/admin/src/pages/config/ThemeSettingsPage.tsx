import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, Eye, Palette, Settings2, Trash2, Upload } from 'lucide-react'
import { useMemo, useRef, useState } from 'react'
import { toast } from 'sonner'
import { deleteTheme, getThemeConfig, getThemes, saveThemeConfig, uploadTheme, type ThemeItem } from '../../api/theme'
import { saveSettings } from '../../api/config'
import { ThemeAppearanceSettings } from './ThemeAppearanceSettings'
import { normalizeThemeFields, ThemeConfigFields } from './ThemeConfigFields'
import { Modal } from '../../components/ui/Modal'
import { PageHeader } from '../../components/ui/PageHeader'
import { requestConfirm } from '../../components/ui/ConfirmDialog'

function idOf(theme: ThemeItem) {
  return String(theme.name || theme.theme || theme.title || 'unknown')
}
function imagesOf(theme: ThemeItem) {
  if (Array.isArray(theme.images)) return theme.images
  if (typeof theme.images === 'string') return [theme.images]
  return [theme.preview, theme.image].filter(Boolean) as string[]
}

export function ThemeSettingsPage() {
  const qc = useQueryClient()
  const inputRef = useRef<HTMLInputElement>(null)
  const query = useQuery({ queryKey: ['themes'], queryFn: getThemes })
  const [preview, setPreview] = useState<ThemeItem | null>(null)
  const [previewIndex, setPreviewIndex] = useState(0)
  const [configTheme, setConfigTheme] = useState<ThemeItem | null>(null)
  const [config, setConfig] = useState<Record<string, unknown>>({})
  const [configLoading, setConfigLoading] = useState(false)
  const [configError, setConfigError] = useState(false)
  const configRequest = useRef(0)

  const themes = useMemo(() => query.data?.themes || [], [query.data])

  const refresh = () => qc.invalidateQueries({ queryKey: ['themes'] })
  const activate = useMutation({
    mutationFn: (theme: string) => saveSettings({ frontend_theme: theme }),
    onSuccess: () => { toast.success('主题已切换'); refresh() },
  })
  const remove = useMutation({
    mutationFn: deleteTheme,
    onSuccess: () => { toast.success('主题已删除'); refresh() },
  })
  const upload = useMutation({
    mutationFn: uploadTheme,
    onSuccess: () => { toast.success('主题上传成功'); refresh() },
  })
  const saveConfig = useMutation({
    mutationFn: ({ theme, next }: { theme: string; next: Record<string, unknown> }) => saveThemeConfig(theme, next),
    onSuccess: () => { toast.success('主题配置已保存'); setConfigTheme(null); refresh() },
  })

  async function openConfig(theme: ThemeItem) {
    const request = ++configRequest.current
    setConfigTheme(theme)
    setConfig({})
    setConfigError(false)
    setConfigLoading(true)
    try {
      const data = await getThemeConfig(idOf(theme))
      if (request === configRequest.current) setConfig(data || {})
    } catch {
      if (request === configRequest.current) setConfigError(true)
    } finally {
      if (request === configRequest.current) setConfigLoading(false)
    }
  }

  function closeConfig() {
    ++configRequest.current
    setConfigTheme(null)
  }

  const configFields = normalizeThemeFields(configTheme?.configs)

  const previewImages = preview ? imagesOf(preview) : []
  const activeImage = previewImages[previewIndex] || ''

  return <>
    <PageHeader
      title="主题管理"
      description="统一管理用户端主题：上传、预览、切换、外观与各主题独立配置。"
      action={<><input ref={inputRef} type="file" accept=".zip" hidden onChange={e => {
        const file = e.target.files?.[0]
        if (file) upload.mutate(file)
        e.currentTarget.value = ''
      }}/><button className="button primary" onClick={() => inputRef.current?.click()} disabled={upload.isPending}><Upload size={16}/>{upload.isPending ? '上传中…' : '上传主题'}</button></>}
    />

    <ThemeAppearanceSettings />

    <div className="theme-management-heading">
      <h2>已安装主题</h2>
      <span>选择主题卡片的“配置”以编辑该主题声明的设置项。</span>
    </div>

    {query.isLoading ? <div className="card">加载主题…</div> : query.isError ? (
      <div className="card" role="alert">主题列表加载失败。<button className="button" onClick={() => query.refetch()}>重试</button></div>
    ) : <div className="theme-grid">
      {themes.map(theme => {
        const id = idOf(theme)
        const image = imagesOf(theme)[0]
        const active = Boolean(theme.is_active || theme.active)
        const canDelete = theme.can_delete !== false && !theme.is_system && !active
        return <article className="theme-card" key={id}>
          <div className="theme-cover" style={image ? { backgroundImage: `url("${image}")` } : undefined}>
            {!image && <Palette size={38}/>}
            {active && <span className="theme-active-badge">当前主题</span>}
          </div>
          <div className="theme-content">
            <div><h3>{theme.title || theme.name || theme.theme || id}</h3><p>{theme.description || '暂无描述'} · {theme.version || 'unknown'}{theme.is_system ? ' · 系统内置' : ''}</p></div>
            <div className="theme-actions">
              <button className="icon-button" title="预览" onClick={() => { setPreview(theme); setPreviewIndex(0) }}><Eye size={16}/></button>
              <button className="icon-button" title="配置" onClick={() => openConfig(theme)}><Settings2 size={16}/></button>
              {!active && <button className="button" onClick={() => activate.mutate(id)}>切换</button>}
              <button className="icon-button danger" disabled={!canDelete} title={active ? '当前主题不能删除' : theme.is_system || theme.can_delete === false ? '系统主题不能删除' : '删除'} onClick={() => requestConfirm({ title: '删除主题', message: `确认删除主题 ${id}？`, danger: true, confirmLabel: '删除', action: () => remove.mutate(id) })}><Trash2 size={16}/></button>
            </div>
          </div>
        </article>
      })}
      {!themes.length && <div className="card empty-state"><strong>暂无主题数据</strong><p>可先上传主题包，或检查 /theme/getThemes 返回结构。</p></div>}
    </div>}

    <Modal open={!!preview} title={preview ? `预览：${idOf(preview)}` : '预览'} onClose={() => setPreview(null)}>
      {activeImage ? <div className="theme-preview">
        <img src={activeImage} alt="theme preview"/>
        {previewImages.length > 1 && <div className="preview-nav">
          <button className="icon-button" onClick={() => setPreviewIndex(i => i === 0 ? previewImages.length - 1 : i - 1)}><ChevronLeft size={18}/></button>
          <span>{previewIndex + 1} / {previewImages.length}</span>
          <button className="icon-button" onClick={() => setPreviewIndex(i => i === previewImages.length - 1 ? 0 : i + 1)}><ChevronRight size={18}/></button>
        </div>}
      </div> : <div className="empty-state">该主题没有预览图。</div>}
    </Modal>

    <Modal open={!!configTheme} title={configTheme ? `配置：${idOf(configTheme)}` : '主题配置'} onClose={closeConfig}>
      {configLoading ? (
        <div role="status">正在读取主题配置…</div>
      ) : configError ? (
        <div role="alert">主题配置加载失败，请关闭后重试。不会覆盖已有配置。</div>
      ) : configFields.length ? (
        <>
          <ThemeConfigFields
            fields={configFields}
            values={config}
            onChange={(name, value) => setConfig(previous => ({ ...previous, [name]: value }))}
          />
          {saveConfig.isError && <p role="alert">配置保存失败，请检查设置后重试。</p>}
          <div className="card-actions">
            <button className="button" onClick={closeConfig}>取消</button>
            <button className="button primary" onClick={() => {
              if (configTheme) saveConfig.mutate({ theme: idOf(configTheme), next: config })
            }} disabled={saveConfig.isPending}>
              {saveConfig.isPending ? '保存中…' : '保存配置'}
            </button>
          </div>
        </>
      ) : (
        <div className="empty-state">这个主题没有声明可配置项，无需保存。</div>
      )}
    </Modal>
  </>
}
