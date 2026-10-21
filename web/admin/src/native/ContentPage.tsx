import { t, useLocale, getLocale } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { TagInput } from '../components/ui/TagInput'
import { MarkdownEditor } from '../components/ui/MarkdownEditor'
import { useListPagination } from './useListPagination'
import { Archive, Eye, EyeOff, RefreshCw, Plus, Search, Save, GripVertical } from 'lucide-react'
import './support-reference.css'
import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { ManagementFeedback, managementError } from './Feedback'
import { ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import type { Column } from '../components/ui/DataTable'
import { requestConfirm } from '../components/ui/ConfirmDialog'
import { RowActionsMenu } from '../components/ui/RowActionsMenu'
import { Tag } from '../components/ui/Tag'
import { Modal } from '../components/ui/Modal'

type Kind = 'notices' | 'knowledge'
type Row = CoreOutput<'content.notices.list'>[number] | CoreOutput<'content.knowledge.list'>[number]
type ListResult = { data: Row[]; meta: { page: number; per_page: number; total: number; last_page: number }; requestId: string }
type DetailResult = { data: CoreOutput<'content.notices.get'> | CoreOutput<'content.knowledge.get'>; meta: Record<string, never>; requestId: string }
type Draft = { title: string; text: string; category: string; language: string; image: string; tags: string[]; popup: boolean; show: boolean; sort: number; audience: 'authenticated' | 'subscribed'; publish: string; expire: string }
const blank = (): Draft => ({ title: '', text: '', category: '', language: 'zh-CN', image: '', tags: [], popup: false, show: false, sort: 0, audience: 'authenticated', publish: '', expire: '' })
const languages = [['zh-CN', '简体中文'], ['zh-TW', '繁體中文'], ['en-US', 'English'], ['ja-JP', '日本語'], ['ko-KR', '한국어'], ['ru-RU', 'Русский'], ['fr-FR', 'Français'], ['de-DE', 'Deutsch'], ['es-ES', 'Español']] as const
const denied = (error: unknown) => error instanceof CoreAdminApiError && [401, 403].includes(error.status)
function localTime(value: number | null) { if (value === null) return ''; const date = new Date(value * 1000); return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16) }
function unixTime(value: string) { if (!value) return null; const time = Date.parse(value); if (!Number.isFinite(time)) throw new Error(t("发布时间格式无效")); return Math.floor(time / 1000) }

export function ContentPage({ kind }: { kind: Kind }) {
  useLocale()
  const client = useQueryClient(), state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const { page, setPage, perPage, setPerPage } = useListPagination(20)
  const [search, setSearch] = useState(''), [title, setTitle] = useState('')
  const [categoryFilter, setCategoryFilter] = useState(''), [visibilityFilter, setVisibilityFilter] = useState('')
  const [customLanguage, setCustomLanguage] = useState(false), [draftTouched, setDraftTouched] = useState(false)
  const [selected, setSelected] = useState(''), [editing, setEditing] = useState(false), [draft, setDraft] = useState(blank)
  const [sortRows, setSortRows] = useState<Row[]>([]), [sorting, setSorting] = useState(false), [localError, setLocalError] = useState('')
  const loadedDraft = useRef('')
  const baseline = useRef(JSON.stringify(blank()))
  const editor = useRef<HTMLDivElement>(null), titleInput = useRef<HTMLInputElement>(null)
  const focusEditor = useRef(false), draggedRow = useRef('')
  const [editorRequest, setEditorRequest] = useState(0)
  const activeEditor = useRef(editorRequest); activeEditor.current = editorRequest
  const [dragTarget, setDragTarget] = useState(''), [sortMessage, setSortMessage] = useState<{ title: string; position: number }>()
  const view = useRef({ kind, generation: 0 })
  if (view.current.kind !== kind) view.current = { kind, generation: view.current.generation + 1 }
  const viewGeneration = view.current.generation
  const [draftVersion, setDraftVersion] = useState('')
  const queryKey = ['native-admin', 'content', kind]
  const list = useQuery<ListResult>({ queryKey: [...queryKey, 'list', page, perPage, title], queryFn: ({ signal }) => kind === 'notices'
    ? admin.read('content.notices.list', { page, per_page: perPage, ...(title ? { title } : {}) }, signal)
    : admin.read('content.knowledge.list', { page, per_page: perPage, ...(title ? { title } : {}) }, signal) })
  const detail = useQuery<DetailResult>({ queryKey: [...queryKey, 'detail', selected], enabled: !!selected, queryFn: ({ signal }) => kind === 'notices'
    ? admin.read('content.notices.get', { content_id: selected }, signal) : admin.read('content.knowledge.get', { content_id: selected }, signal) })
  const audit = useInfiniteQuery({ queryKey: ['native-admin', 'content', 'audit'], initialPageParam: '0',
    queryFn: ({ signal, pageParam }) => admin.read('content.audit.list', { after_id: pageParam, limit: 10 }, signal),
    getNextPageParam: result => result.meta.has_more ? result.meta.next_after_id : undefined })
  const blocked = denied(list.error) || denied(detail.error) || denied(audit.error) || denied(state.error)
  const locked = state.busy || !!state.pending || blocked
  const interaction = useRef({ mounted: true, locked, fetching: list.isFetching || list.isError })
  interaction.current.locked = locked; interaction.current.fetching = list.isFetching || list.isError
  useEffect(() => { interaction.current.mounted = true; return () => { interaction.current.mounted = false } }, [])
  const ownPending = state.pending?.operation.startsWith('content.' + kind + '.') ? state.pending : undefined
  useEffect(() => {
    loadedDraft.current = ''; setSelected(''); setEditing(false); setDraft(blank()); setDraftVersion('')
    baseline.current = JSON.stringify(blank()); setDraftTouched(false); setCustomLanguage(false); setCategoryFilter(''); setVisibilityFilter('')
    setPage(1); setSearch(''); setTitle(''); setSortRows([]); setSorting(false); setLocalError('')
    draggedRow.current = ''; setDragTarget(''); setSortMessage(undefined); focusEditor.current = false
  }, [kind])
  useEffect(() => {
    const row = detail.data?.data
    if (!row || detail.isError || detail.isFetching || blocked || loadedDraft.current === kind + ':' + selected) return
    loadedDraft.current = kind + ':' + selected
    setDraftVersion(row.row_version)
    const next: Draft = { title: row.title, text: 'content' in row ? row.content : row.body, category: 'category' in row ? row.category : '',
      language: 'language' in row ? row.language : 'zh-CN', image: 'img_url' in row ? row.img_url ?? '' : '',
      tags: 'tags' in row ? row.tags : [], popup: 'popup' in row && row.popup, show: row.show, sort: row.sort,
      audience: row.audience, publish: localTime(row.publish_at), expire: localTime(row.expire_at) }
    baseline.current = JSON.stringify(next); setDraft(next); setDraftTouched(false); setCustomLanguage(false)
  }, [detail.data, detail.isError, detail.isFetching, blocked, kind, selected])
  useEffect(() => {
    if (!editing || blocked || !focusEditor.current || selected && (!detail.data || detail.isError || detail.isFetching)) return
    titleInput.current?.focus({ preventScroll: true })
    focusEditor.current = false
  }, [editing, blocked, selected, detail.data, detail.isError, detail.isFetching, editorRequest])
  useEffect(() => { if (blocked) { setDraft(blank()); setEditing(false); setSortRows([]) } }, [blocked])
  const dirty = draftTouched || JSON.stringify(draft) !== baseline.current
  useDirtyDraft(editing && dirty && !blocked && !state.pending)
  useEffect(() => {
    if (!editing || !dirty) return
    const preventUnload = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', preventUnload)
    return () => window.removeEventListener('beforeunload', preventUnload)
  }, [editing, dirty])
  function closeEditor() {
    if (state.busy) return
    const generation = workflowActions.generation(), request = editorRequest
    const close = () => {
      if (!interaction.current.mounted || generation !== workflowActions.generation() || view.current.generation !== viewGeneration || activeEditor.current !== request || workflowActions.snapshot().busy) return
      loadedDraft.current = ''; setEditing(false); setSelected(''); setDraft(blank()); setDraftVersion(''); setDraftTouched(false)
    }
    if (dirty) requestConfirm({ title: t("放弃未保存的内容？"), message: t("关闭编辑将丢弃当前草稿。已经提交但结果未知的原操作仍会保留，需单独确认结果。"), confirmLabel: t("放弃并关闭"), danger: true, action: close })
    else close()
  }
  function openEditor(row?: Row) {
    if (locked || editing) return
    loadedDraft.current = ''; focusEditor.current = true; setEditorRequest(value => value + 1)
    setSelected(row?.id ?? ''); setEditing(true); setLocalError(''); setDraftTouched(false); setCustomLanguage(false)
    if (!row) { const next = blank(); baseline.current = JSON.stringify(next); setDraft(next); setDraftVersion('') }
  }
  async function refresh() {
    const generation = workflowActions.generation()
    try {
      await client.refetchQueries({ queryKey: ['native-admin', 'content'], type: 'active' }, { throwOnError: true })
      if (!interaction.current.mounted || generation !== workflowActions.generation() || view.current.generation !== viewGeneration) return
      workflowActions.clearError()
      workflowActions.confirmReadAuthorization('content.' + kind + '.')
    } catch { /* Query feedback retains the failed permission check. */ }
  }
  async function perform(run: () => Promise<void>) {
    const generation = workflowActions.generation()
    setLocalError('')
    try { await run() } catch (error) {
      if (!interaction.current.mounted || generation !== workflowActions.generation() || view.current.generation !== viewGeneration) return
      setLocalError(managementError(error, false))
      if (error instanceof CoreAdminApiError && error.status === 409 && !error.needsStateConfirmation) {
        setEditing(false); setSelected(''); loadedDraft.current = ''; await refresh()
      }
    }
  }
  function update<K extends keyof Draft>(key: K, value: Draft[K]) { setDraft(old => ({ ...old, [key]: value })) }
  async function save() {
    if (locked || selected && (!detail.data || detail.isError || detail.isFetching)) return
    const generation = workflowActions.generation()
    await perform(async () => {
      const common = { title: draft.title, show: draft.show, sort: draft.sort, audience: draft.audience, publish_at: unixTime(draft.publish), expire_at: unixTime(draft.expire) }
      if (kind === 'notices') {
        const input = { ...common, content: draft.text, img_url: draft.image || null, tags: draft.tags, popup: draft.popup }
        if (selected) await workflowActions.run('content.notices.update', { ...input, content_id: selected, expected_version: draftVersion })
        else await workflowActions.run('content.notices.create', input)
      } else {
        const input = { ...common, body: draft.text, category: draft.category, language: draft.language }
        if (selected) await workflowActions.run('content.knowledge.update', { ...input, content_id: selected, expected_version: draftVersion })
        else await workflowActions.run('content.knowledge.create', input)
      }
      if (!interaction.current.mounted || generation !== workflowActions.generation() || view.current.generation !== viewGeneration) return
      loadedDraft.current = ''; setEditing(false); setSelected(''); setDraft(blank()); await refresh()
    })
  }
  async function change(row: Row, remove: boolean) {
    if (locked || list.isFetching || list.isError) return
    const generation = workflowActions.generation()
    await perform(async () => {
      const input = { content_id: row.id, expected_version: row.row_version }
      if (kind === 'notices') {
        if (remove) await workflowActions.run('content.notices.delete', input)
        else await workflowActions.run('content.notices.visibility.toggle', { ...input, show: !row.show })
      } else {
        if (remove) await workflowActions.run('content.knowledge.delete', input)
        else await workflowActions.run('content.knowledge.visibility.toggle', { ...input, show: !row.show })
      }
      if (!interaction.current.mounted || generation !== workflowActions.generation() || view.current.generation !== viewGeneration) return
      if (selected === row.id) { loadedDraft.current = ''; setSelected(''); setEditing(false) }
      await refresh()
    })
  }
  async function recover() {
    const action = ownPending
    if (!action || state.busy || blocked) return
    const generation = workflowActions.generation()
    await perform(async () => { await workflowActions.run(action.operation, action.input); if (!interaction.current.mounted || generation !== workflowActions.generation() || view.current.generation !== viewGeneration) return; loadedDraft.current = ''; setEditing(false); setSelected(''); setSortRows([]); await refresh() })
  }
  async function prepareSort() {
    if (locked || sorting) return
    const generation = workflowActions.generation()
    setSorting(true)
    setSortMessage(undefined); draggedRow.current = ''; setDragTarget('')
    await perform(async () => {
      const rows: Row[] = []
      for (let current = 1; current <= 50; current++) {
        const result = kind === 'notices' ? await admin.read('content.notices.list', { page: current, per_page: 20 }) : await admin.read('content.knowledge.list', { page: current, per_page: 20 })
        if (!interaction.current.mounted || generation !== workflowActions.generation() || view.current.generation !== viewGeneration) return
        rows.push(...result.data)
        if (result.meta.total > 1000) throw new Error(t("内容超过 1000 条，请分批编辑排序号"))
        if (current >= result.meta.last_page) { setSortRows(rows); return }
      }
      throw new Error(t("内容数量发生变化，请重新读取"))
    })
    if (interaction.current.mounted && generation === workflowActions.generation() && view.current.generation === viewGeneration) setSorting(false)
  }
  async function saveSort() {
    if (locked || !sortRows.length) return
    const generation = workflowActions.generation()
    await perform(async () => { const input = { items: sortRows.map(row => ({ id: row.id, expected_version: row.row_version })) };
      if (kind === 'notices') await workflowActions.run('content.notices.sort', input); else await workflowActions.run('content.knowledge.sort', input)
      if (!interaction.current.mounted || generation !== workflowActions.generation() || view.current.generation !== viewGeneration) return
      setSortRows([]); await refresh() })
  }
  function reorder(from: number, to: number) {
    if (locked || from === to || from < 0 || to < 0 || from >= sortRows.length || to >= sortRows.length) return
    const rows = [...sortRows], [row] = rows.splice(from, 1)
    rows.splice(to, 0, row!)
    setSortRows(rows); setSortMessage({ title: row!.title, position: to + 1 })
  }
  function archive(row: Row) {
    const generation = workflowActions.generation()
    requestConfirm({ title: t("归档内容"), message: t("归档「{v0}」后用户端将无法访问，审计记录仍保留。确定归档？", { v0: row.title }), confirmLabel: t("确认归档"), danger: true,
      action: () => {
        const current = workflowActions.snapshot()
        if (!interaction.current.mounted || interaction.current.locked || interaction.current.fetching || generation !== workflowActions.generation() || view.current.generation !== viewGeneration || current.busy || current.pending) return
        void change(row, true)
      } })
  }
  const columns: Column<Row>[] = [
    { key: 'title', header: t("标题"), render: row => <div className="native-row-identity"><strong>{row.title}</strong><small>#{row.id}{t("· 版本")}{row.row_version}</small></div> },
    { key: 'status', header: t("发布状态"), render: row => <Tag tone={row.show ? 'success' : 'neutral'}>{row.show ? t("已启用") : t("草稿")}</Tag> },
    { key: 'scope', header: t("可见范围"), render: row => <Tag tone={row.audience === 'subscribed' ? 'info' : 'neutral'}>{row.audience === 'subscribed' ? t("有效订阅用户") : t("已登录用户")}</Tag> },
    { key: 'sort', header: t("排序"), render: row => row.sort },
    ...(kind === 'knowledge' ? [{ key: 'category', header: t("分类"), render: (row: Row) => 'category' in row ? row.category : '' },
      { key: 'language', header: t("语言"), render: (row: Row) => 'language' in row ? languages.find(([code]) => code === row.language)?.[1] ?? row.language : '' }] : []),
    { key: 'actions', header: t("操作"), render: row => <div className="support-actions"><button className="button" disabled={locked} onClick={() => openEditor(row)}>{t("编辑")}</button><RowActionsMenu label={t('更多操作')} actions={[
      { key: 'visibility', label: row.show ? t('停用') : t('启用'), icon: row.show ? <EyeOff size={14} aria-hidden="true" /> : <Eye size={14} aria-hidden="true" />, disabled: locked || list.isFetching, onSelect: () => void change(row, false) },
      { key: 'archive', label: t('归档'), icon: <Archive size={14} aria-hidden="true" />, danger: true, separator: true, disabled: locked || list.isFetching, onSelect: () => archive(row) },
    ]} /></div> },
  ]
  const pageRows = list.isError || blocked ? [] : list.data?.data ?? []
  const visibleRows = pageRows.filter(row => (!categoryFilter || 'category' in row && row.category === categoryFilter)
    && (!visibilityFilter || row.show === (visibilityFilter === 'shown')))
  const formId = 'native-' + kind + '-editor-form'
  const useCustomLanguage = customLanguage || !languages.some(([code]) => code === draft.language)
  return <div className="native-management-page support-page content-page">
    <PageHeader title={kind === 'notices' ? t("公告管理") : t("知识库管理")} description={t("管理内容、发布时段、可见范围和排序。")} action={<><button className="button" disabled={state.busy || list.isFetching} onClick={() => { setLocalError(''); void refresh() }}><RefreshCw size={15} />{t("刷新")}</button><button className="button" disabled={locked} onClick={() => openEditor()}><Plus size={15} />{t("新增")}</button></>} />
    {ownPending ? <section className="list-card support-pending" role="status"><h2>{t("操作等待确认")}</h2><p>{t("上次保存结果尚未确定，请确认后再继续编辑。")}</p><details className="support-help"><summary>{t("查看操作标识")}</summary><p>{ownPending.operation} · {ownPending.key}</p></details><button className="button" disabled={state.busy || blocked} onClick={() => void recover()}>{t("使用原操作确认结果")}</button></section> : null}
    {localError || state.error ? <p role="alert">{localError ? t(localError) : managementError(state.error)}</p> : null}
    {blocked ? <p role="alert">{t("当前无法确认内容访问权限，已隐藏数据。")}</p> : null}
    <ReadList maxPageSize={20} columnSettingsKey={kind} title={"内容列表"} rows={visibleRows} columns={columns} meta={list.data?.meta} perPage={perPage} onPageSize={setPerPage} page={page} ready={!!list.data && !blocked} loading={list.isFetching} error={list.isError ? list.error : undefined} onRetry={() => void refresh()} onPage={setPage} mobilePrimaryKey="title">
      <div className="support-heading"><div><h2>{kind === 'notices' ? t("公告列表") : t("文章列表")}</h2><p>{t("管理发布内容与展示顺序")}</p></div></div><form className="native-filters" onSubmit={event => { event.preventDefault(); setPage(1); setTitle(search) }}><label>{t("标题")}<input placeholder={t("搜索标题")} value={search} maxLength={255} onChange={event => setSearch(event.target.value)} /></label><button className="button"><Search size={14} />{t("查询")}</button><button type="button" className="button" disabled={locked || sorting} onClick={() => void prepareSort()}>{sorting ? t("正在读取全量排序…") : t("编辑完整排序")}</button>
        {kind === 'knowledge' ? <label>{t("当前页分类")}<select value={categoryFilter} onChange={event => setCategoryFilter(event.target.value)}><option value="">{t("全部分类")}</option>{[...new Set(pageRows.flatMap(row => 'category' in row ? [row.category] : []))].map(category => <option key={category} value={category}>{category}</option>)}</select></label> : null}
        <label>{t("当前页发布状态")}<select value={visibilityFilter} onChange={event => setVisibilityFilter(event.target.value)}><option value="">{t("全部状态")}</option><option value="shown">{t("已启用")}</option><option value="hidden">{t("草稿")}</option></select></label>
        {categoryFilter || visibilityFilter ? <button type="button" className="button" onClick={() => { setCategoryFilter(''); setVisibilityFilter('') }}>{t("清除当前页筛选")}</button> : null}
      </form><p className="support-filter-scope">{t('标题在全部分页中查询；分类与发布状态只筛选当前页，不改变分页总数。当前显示 {visible} / {total} 项。', { visible: visibleRows.length, total: pageRows.length })}</p>
    </ReadList>
    {sortRows.length && !blocked ? <section className="list-card support-sort"><div className="support-heading"><div><h2>{t("完整排序")}</h2><p>{t("拖动内容调整顺序，也可使用上移或下移按钮，确认后统一保存")}</p></div><Tag>{sortRows.length}{t("项")}</Tag></div>
      <p className="support-sort-feedback" role="status" aria-live="polite">{sortMessage ? t("已将「{v0}」移动到第 {v1} 项，保存后生效", { v0: sortMessage.title, v1: sortMessage.position }) : t("当前为全部内容的展示顺序，修改后需保存排序。")}</p>
      <ol>{sortRows.map((row, index) => <li key={row.id} data-drop-target={dragTarget === row.id || undefined}
        onDragOver={event => { if (locked || !draggedRow.current) return; event.preventDefault(); event.dataTransfer.dropEffect = 'move'; setDragTarget(row.id) }}
        onDragLeave={event => { if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setDragTarget('') }}
        onDrop={event => { event.preventDefault(); if (draggedRow.current) reorder(sortRows.findIndex(item => item.id === draggedRow.current), index); draggedRow.current = ''; setDragTarget('') }}>
        <div className="support-sort-identity"><span className="support-sort-handle" draggable={!locked} title={t("拖动调整顺序")} aria-hidden="true"
          onDragStart={event => { if (locked) { event.preventDefault(); return }; draggedRow.current = row.id; event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', row.id) }}
          onDragEnd={() => { draggedRow.current = ''; setDragTarget('') }}><GripVertical size={18} /></span><span className="support-sort-title"><small>{index + 1}</small>{row.title}</span></div>
        <div className="support-actions"><button className="button" disabled={locked || index === 0} onClick={() => reorder(index, index - 1)}>{t("上移")}</button><button className="button" disabled={locked || index === sortRows.length - 1} onClick={() => reorder(index, index + 1)}>{t("下移")}</button></div>
      </li>)}</ol><div className="support-actions"><button className="button" disabled={locked} onClick={() => void saveSort()}>{t("保存排序")}</button><button className="button" disabled={locked} onClick={() => { setSortRows([]); setSortMessage(undefined); draggedRow.current = ''; setDragTarget('') }}>{t("取消")}</button></div></section> : null}
    <Modal open={editing && !blocked} title={selected ? t("编辑内容") : t("新增草稿")} subtitle={t("设置正文、可见范围与发布时间")} wide onClose={closeEditor}
      className="support-page support-content-editor admin-form-dialog" bodyClassName="support-content-editor-body"
      headerAction={<Tag tone={draft.show ? 'success' : 'neutral'}>{draft.show ? t("启用发布") : t("草稿")}</Tag>}
      footer={<div className="support-form-footer"><span>{dirty ? t("有未保存的修改") : selected ? t("编辑当前内容") : t("保存后可继续调整发布设置")}</span><div className="support-actions"><button type="submit" form={formId} className="button primary" disabled={locked || !!selected && (!detail.data || detail.isError || detail.isFetching)}><Save size={14} />{t("保存")}</button><button className="button" type="button" disabled={state.busy} onClick={closeEditor}>{t("关闭编辑")}</button></div></div>}>
      <div ref={editor}>
      {selected ? <ManagementFeedback error={detail.isError ? detail.error : undefined} loading={detail.isFetching} onRetry={() => void detail.refetch()} /> : null}
      {!selected || detail.data && !detail.isError && !detail.isFetching ? <form id={formId} className="native-content-form support-editor-grid" onChangeCapture={() => setDraftTouched(true)} onSubmit={event => { event.preventDefault(); void save() }}>
        <label className="support-wide-field">{t("标题")}<input ref={titleInput} data-autofocus value={draft.title} maxLength={255} required disabled={locked} onChange={event => update('title', event.target.value)} /></label>
        {kind === 'knowledge' ? <><label>{t("分类")}<input value={draft.category} maxLength={255} required disabled={locked} onChange={event => update('category', event.target.value)} /></label><label>{t("语言")}<select value={useCustomLanguage ? '__custom__' : draft.language} disabled={locked} onChange={event => { const custom = event.target.value === '__custom__'; setCustomLanguage(custom); if (!custom) update('language', event.target.value) }}>{languages.map(([code, label]) => <option value={code} key={code}>{label} · {code}</option>)}<option value="__custom__">{t("自定义语言代码")}</option></select></label>{useCustomLanguage ? <label>{t("自定义语言代码")}<input value={draft.language} maxLength={32} required disabled={locked} onChange={event => update('language', event.target.value)} /></label> : null}</> : <><label className="support-wide-field">{t("封面 URL")}<input type="url" value={draft.image} maxLength={2048} disabled={locked} onChange={event => update('image', event.target.value)} /></label><div><label htmlFor="content-tags">{t("标签")}</label><TagInput key={editorRequest} id="content-tags" value={draft.tags} disabled={locked} onChange={value => update('tags', value)} /></div><label className="support-toggle"><input type="checkbox" checked={draft.popup} disabled={locked} onChange={event => update('popup', event.target.checked)} />{t("弹窗公告")}</label></>}
        <label className="support-wide-field" htmlFor={'native-' + kind + '-body'}>{t("正文")}</label>
        <div className="support-wide-field"><MarkdownEditor id={'native-' + kind + '-body'} value={draft.text} disabled={locked} onChange={value => update('text', value)} /></div>
        <label>{t("可见范围")}<select value={draft.audience} disabled={locked} onChange={event => update('audience', event.target.value as Draft['audience'])}><option value="authenticated">{t("已登录用户")}</option><option value="subscribed">{t("有效订阅用户")}</option></select></label>
        <label>{t("发布时间（留空立即生效）")}<input type="datetime-local" value={draft.publish} disabled={locked} onChange={event => update('publish', event.target.value)} /></label><label>{t("到期时间（留空长期有效）")}<input type="datetime-local" value={draft.expire} disabled={locked} onChange={event => update('expire', event.target.value)} /></label>
        <label>{t("排序号")}<input type="number" min={0} max={2147483647} value={draft.sort} required disabled={locked} onChange={event => update('sort', Number(event.target.value))} /></label><label className="support-toggle"><input type="checkbox" checked={draft.show} disabled={locked} onChange={event => update('show', event.target.checked)} />{t("启用发布")}</label>
      </form> : null}
      </div></Modal>
    {!blocked ? <section className="list-card"><div className="support-heading"><div><h2>{t("内容变更记录")}</h2><p>{t("最近的编辑与发布操作")}</p></div></div><ManagementFeedback error={audit.isError ? audit.error : undefined} loading={audit.isFetching} onRetry={() => void audit.refetch()} /><ol className="support-timeline">{!audit.isError ? audit.data?.pages.flatMap(result => result.data).map(row => <li key={row.id}><div className="support-audit-heading"><strong>{row.operation}</strong><time>{new Date(row.occurred_at * 1000).toLocaleString(getLocale())}</time></div><p>#{row.id} · {row.kind}{t("· 管理员")}{row.actor_id} · {row.changes.length}{t("条记录")}</p></li>) : null}</ol>{audit.hasNextPage ? <button className="button" disabled={audit.isFetching} onClick={() => void audit.fetchNextPage()}>{t("继续读取审计")}</button> : null}</section> : null}
  </div>
}
