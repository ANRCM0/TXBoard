import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, Eye, Palette, RotateCcw, Settings2, Trash2, Upload } from 'lucide-react'
import { useMemo, useRef, useState } from 'react'
import { toast } from 'sonner'
import { deleteTheme, getThemeConfig, getThemes, saveThemeConfig, uploadTheme, uploadThemeV2, rollbackThemeV2, type ThemeItem } from '../../api/theme'
import { saveSettings } from '../../api/config'
import { normalizeThemeFields, ThemeConfigFields } from './ThemeConfigFields'
import { Modal } from '../../components/ui/Modal'
import { PageHeader } from '../../components/ui/PageHeader'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import { EmptyState } from '../../components/ui/EmptyState'

function idOf(theme: ThemeItem) {
  return String(theme.name || theme.theme || theme.title || 'unknown')
}
function imagesOf(theme: ThemeItem) {
  const candidates = [
    ...(Array.isArray(theme.images) ? theme.images : typeof theme.images === 'string' ? [theme.images] : []),
    theme.preview, theme.image,
  ]
  return [...new Set(candidates.filter((image): image is string => typeof image === 'string' && image.trim().length > 0))]
}

export function ThemeSettingsPage() {
  const qc = useQueryClient()
  const inputRef = useRef<HTMLInputElement>(null)
  const inputV2Ref = useRef<HTMLInputElement>(null)
  const query = useQuery({ queryKey: ['themes'], queryFn: getThemes })
  const [preview, setPreview] = useState<ThemeItem | null>(null)
  const [previewIndex, setPreviewIndex] = useState(0)
  const [configTheme, setConfigTheme] = useState<ThemeItem | null>(null)
  const [config, setConfig] = useState<Record<string, unknown>>({})
  const [configLoading, setConfigLoading] = useState(false)
  const [configError, setConfigError] = useState(false)
  const configRequest = useRef(0)

  const themes = useMemo(() => query.data?.themes || [], [query.data])
  const withImages = themes.filter(theme => imagesOf(theme).length > 0)
  const withoutImages = themes.filter(theme => imagesOf(theme).length === 0)

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
  const uploadV2 = useMutation({
    mutationFn: uploadThemeV2,
    onSuccess: result => { toast.success(`SPA 主题 ${result.theme} 已安装，请显式切换启用`); refresh() },
    onError: () => toast.error('Theme v2 上传失败，请检查包完整性或运行时版本'),
  })
  const rollbackV2 = useMutation({
    mutationFn: rollbackThemeV2,
    onSuccess: () => { toast.success('上一版本已恢复'); refresh() },
    onError: () => toast.error('回滚失败：上一版本不存在或不兼容'),
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

  function themeActions(theme: ThemeItem) {
    const id = idOf(theme)
    const active = Boolean(theme.is_active || theme.active)
    const canDelete = theme.can_delete !== false && !theme.is_system && !active
    return <div className="admin-v2-theme-actions">
      <button type="button" className="icon-button" aria-label={'预览主题 ' + id}
        title="预览" onClick={() => { setPreview(theme); setPreviewIndex(0) }}><Eye size={16}/></button>
      <button type="button" className="button" title="配置此主题" onClick={() => void openConfig(theme)}>
        <Settings2 size={16}/>设置
      </button>
      {!active ? <button type="button" className="button" disabled={activate.isPending}
        onClick={() => activate.mutate(id)}>切换</button> : null}
      {theme.format === 2 && theme.can_rollback ? (
        <button type="button" className="button" disabled={rollbackV2.isPending}
          onClick={() => requestConfirm({
            title: '回滚 SPA 主题版本',
            message: '确认将主题 ' + id + ' 恢复为上一版本？当前版本将保留为可再次回滚的版本。',
            confirmLabel:'回滚', action:() => rollbackV2.mutate(id),
          })}>
          <RotateCcw size={16}/>回滚版本
        </button>
      ) : null}
      <button type="button" className="icon-button danger" disabled={!canDelete}
        aria-label={'删除主题 ' + id}
        title={active ? '当前主题不能删除' : theme.is_system || theme.can_delete === false ? '系统主题不能删除' : '删除'}
        onClick={() => requestConfirm({
          title:'删除主题', message:'确认删除主题 ' + id + '？',
          danger:true, confirmLabel:'删除', action:() => remove.mutate(id),
        })}><Trash2 size={16}/></button>
    </div>
  }

  return <>
    <PageHeader
      title="主题管理"
      description="统一管理用户端主题：上传、预览、切换以及各主题自身声明的配置项。"
      action={<><input ref={inputRef} type="file" accept=".zip" hidden onChange={e => {
        const file = e.target.files?.[0]
        if (file) upload.mutate(file)
        e.currentTarget.value = ''
      }}/><button className="button primary" onClick={() => inputRef.current?.click()} disabled={upload.isPending}><Upload size={16}/>{upload.isPending ? '上传中…' : '上传主题'}</button><input ref={inputV2Ref} type="file" accept=".zip" hidden onChange={e => {
        const file = e.target.files?.[0]
        if (file) uploadV2.mutate(file)
        e.currentTarget.value = ''
      }}/><button className="button" onClick={() => inputV2Ref.current?.click()} disabled={uploadV2.isPending}>
        <Upload size={16}/>{uploadV2.isPending ? '安装中…' : '上传 SPA v2'}
      </button></>}
    />

    <div className="theme-management-heading">
      <h2>已安装主题</h2>
      <span>点击主题卡片的“设置”，编辑该主题专属配置；启用主题后自动应用到用户前端。</span>
    </div>

    {query.isLoading ? <div className="admin-v2-empty" role="status" style={{padding:24}}>加载主题…</div> : query.isError ? (
      <div className="admin-v2-empty" role="alert" style={{padding:24}}>
        主题列表加载失败。<button type="button" className="button" onClick={() => void query.refetch()}>重试</button>
      </div>
    ) : !themes.length ? (
      <EmptyState className="admin-v2-empty" icon={<Palette size={23}/>}
        title="还没有主题" description="上传主题包后即可进行预览、配置及切换。"
        action={<button type="button" className="button primary" onClick={() => inputRef.current?.click()}>上传主题</button>}/>
    ) : <div className="admin-v2-theme-sections">
      {withImages.length > 0 ? <section aria-label="有预览图的主题">
        <h3 className="admin-v2-theme-heading">主题预览</h3>
        <div className="admin-v2-theme-gallery">
          {withImages.map(theme => {
            const id = idOf(theme)
            const image = imagesOf(theme)[0]
            const active = Boolean(theme.is_active || theme.active)
            return <article className="admin-v2-theme-card" key={id}>
              <div className="admin-v2-theme-cover" style={{backgroundImage:'url("' + image + '")'}} aria-label={'主题预览：' + id}/>
              <div className="admin-v2-theme-info">
                <div>
                  <h3>{theme.title || theme.name || theme.theme || id}</h3>
                  <p>{theme.description || '暂无描述'} · v{theme.version || 'unknown'}{theme.is_system ? ' · 系统内置' : ''}{theme.format === 2 ? ' · SPA v2' : ''}</p>
                  {active ? <span className="admin-v2-theme-current">当前主题</span> : null}
                </div>
                {themeActions(theme)}
              </div>
            </article>
          })}
        </div>
      </section> : null}
      {withoutImages.length > 0 ? <section aria-label="无预览图的主题">
        {withImages.length > 0 ? <h3 className="admin-v2-theme-heading">其他已安装主题</h3> : null}
        <div className="admin-v2-theme-list" role="list" aria-label="无预览图的主题列表">
          {withoutImages.map(theme => {
            const id = idOf(theme)
            const active = Boolean(theme.is_active || theme.active)
            return <article className="admin-v2-theme-row" role="listitem" key={id}>
              <div className="admin-v2-theme-icon" aria-hidden="true"><Palette size={21}/></div>
              <div className="admin-v2-theme-description">
                <h3>{theme.title || theme.name || theme.theme || id}</h3>
                <p>{theme.description || '暂无描述'} · v{theme.version || 'unknown'}{theme.is_system ? ' · 系统内置' : ''}{theme.format === 2 ? ' · SPA v2' : ''}</p>
                {active ? <span className="admin-v2-theme-current">当前主题</span> : null}
              </div>
              {themeActions(theme)}
            </article>
          })}
        </div>
      </section> : null}
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
