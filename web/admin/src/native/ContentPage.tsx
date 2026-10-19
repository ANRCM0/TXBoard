import { RefreshCw, Plus, Search, Eye, Save } from 'lucide-react'
import './support-reference.css'
import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { ManagementFeedback, managementError } from './Feedback'
import { ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import type { Column } from '../components/ui/DataTable'

type Kind = 'notices' | 'knowledge'
type Row = CoreOutput<'content.notices.list'>[number] | CoreOutput<'content.knowledge.list'>[number]
type ListResult = { data: Row[]; meta: { page: number; per_page: number; total: number; last_page: number }; requestId: string }
type DetailResult = { data: CoreOutput<'content.notices.get'> | CoreOutput<'content.knowledge.get'>; meta: Record<string, never>; requestId: string }
type Draft = { title: string; text: string; category: string; language: string; image: string; tags: string; popup: boolean; show: boolean; sort: number; audience: 'authenticated' | 'subscribed'; publish: string; expire: string }
const blank = (): Draft => ({ title: '', text: '', category: '', language: 'zh-CN', image: '', tags: '', popup: false, show: false, sort: 0, audience: 'authenticated', publish: '', expire: '' })
const denied = (error: unknown) => error instanceof CoreAdminApiError && [401, 403].includes(error.status)
function localTime(value: number | null) { if (value === null) return ''; const date = new Date(value * 1000); return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16) }
function unixTime(value: string) { if (!value) return null; const time = Date.parse(value); if (!Number.isFinite(time)) throw new Error('发布时间格式无效'); return Math.floor(time / 1000) }

export function ContentPage({ kind }: { kind: Kind }) {
  const client = useQueryClient(), state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [page, setPage] = useState(1), [search, setSearch] = useState(''), [title, setTitle] = useState('')
  const [selected, setSelected] = useState(''), [editing, setEditing] = useState(false), [draft, setDraft] = useState(blank)
  const [sortRows, setSortRows] = useState<Row[]>([]), [sorting, setSorting] = useState(false), [localError, setLocalError] = useState('')
  const loadedDraft = useRef('')
  const view = useRef({ kind, generation: 0 })
  if (view.current.kind !== kind) view.current = { kind, generation: view.current.generation + 1 }
  const viewGeneration = view.current.generation
  const [draftVersion, setDraftVersion] = useState('')
  const queryKey = ['native-admin', 'content', kind]
  const list = useQuery<ListResult>({ queryKey: [...queryKey, 'list', page, title], queryFn: ({ signal }) => kind === 'notices'
    ? admin.read('content.notices.list', { page, per_page: 20, ...(title ? { title } : {}) }, signal)
    : admin.read('content.knowledge.list', { page, per_page: 20, ...(title ? { title } : {}) }, signal) })
  const detail = useQuery<DetailResult>({ queryKey: [...queryKey, 'detail', selected], enabled: !!selected, queryFn: ({ signal }) => kind === 'notices'
    ? admin.read('content.notices.get', { content_id: selected }, signal) : admin.read('content.knowledge.get', { content_id: selected }, signal) })
  const audit = useInfiniteQuery({ queryKey: ['native-admin', 'content', 'audit'], initialPageParam: '0',
    queryFn: ({ signal, pageParam }) => admin.read('content.audit.list', { after_id: pageParam, limit: 10 }, signal),
    getNextPageParam: result => result.meta.has_more ? result.meta.next_after_id : undefined })
  const blocked = denied(list.error) || denied(detail.error) || denied(audit.error) || denied(state.error)
  const locked = state.busy || !!state.pending || blocked
  const ownPending = state.pending?.operation.startsWith('content.' + kind + '.') ? state.pending : undefined
  useEffect(() => {
    loadedDraft.current = ''; setSelected(''); setEditing(false); setDraft(blank()); setDraftVersion('')
    setPage(1); setSearch(''); setTitle(''); setSortRows([]); setSorting(false); setLocalError('')
  }, [kind])
  useEffect(() => {
    const row = detail.data?.data
    if (!row || detail.isError || blocked || loadedDraft.current === kind + ':' + selected) return
    loadedDraft.current = kind + ':' + selected
    setDraftVersion(row.row_version)
    setDraft({ title: row.title, text: 'content' in row ? row.content : row.body, category: 'category' in row ? row.category : '',
      language: 'language' in row ? row.language : 'zh-CN', image: 'img_url' in row ? row.img_url ?? '' : '',
      tags: 'tags' in row ? row.tags.join(', ') : '', popup: 'popup' in row && row.popup, show: row.show, sort: row.sort,
      audience: row.audience, publish: localTime(row.publish_at), expire: localTime(row.expire_at) })
  }, [detail.data, detail.isError, blocked, kind, selected])
  useEffect(() => { if (blocked) { setDraft(blank()); setEditing(false); setSortRows([]) } }, [blocked])
  async function refresh() {
    const generation = workflowActions.generation()
    try {
      await client.refetchQueries({ queryKey: ['native-admin', 'content'], type: 'active' }, { throwOnError: true })
      if (generation !== workflowActions.generation() || view.current.generation !== viewGeneration) return
      workflowActions.clearError()
      workflowActions.confirmReadAuthorization('content.' + kind + '.')
    } catch { /* Query feedback retains the failed permission check. */ }
  }
  async function perform(run: () => Promise<void>) {
    const generation = workflowActions.generation()
    setLocalError('')
    try { await run() } catch (error) {
      if (generation !== workflowActions.generation() || view.current.generation !== viewGeneration) return
      setLocalError(managementError(error))
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
        const input = { ...common, content: draft.text, img_url: draft.image || null, tags: draft.tags.split(',').map(tag => tag.trim()).filter(Boolean), popup: draft.popup }
        if (selected) await workflowActions.run('content.notices.update', { ...input, content_id: selected, expected_version: draftVersion })
        else await workflowActions.run('content.notices.create', input)
      } else {
        const input = { ...common, body: draft.text, category: draft.category, language: draft.language }
        if (selected) await workflowActions.run('content.knowledge.update', { ...input, content_id: selected, expected_version: draftVersion })
        else await workflowActions.run('content.knowledge.create', input)
      }
      if (generation !== workflowActions.generation() || view.current.generation !== viewGeneration) return
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
      if (generation !== workflowActions.generation() || view.current.generation !== viewGeneration) return
      if (selected === row.id) { loadedDraft.current = ''; setSelected(''); setEditing(false) }
      await refresh()
    })
  }
  async function recover() {
    const action = ownPending
    if (!action || state.busy || blocked) return
    const generation = workflowActions.generation()
    await perform(async () => { await workflowActions.run(action.operation, action.input); if (generation !== workflowActions.generation() || view.current.generation !== viewGeneration) return; loadedDraft.current = ''; setEditing(false); setSelected(''); setSortRows([]); await refresh() })
  }
  async function prepareSort() {
    if (locked || sorting) return
    const generation = workflowActions.generation()
    setSorting(true)
    await perform(async () => {
      const rows: Row[] = []
      for (let current = 1; current <= 50; current++) {
        const result = kind === 'notices' ? await admin.read('content.notices.list', { page: current, per_page: 20 }) : await admin.read('content.knowledge.list', { page: current, per_page: 20 })
        if (generation !== workflowActions.generation() || view.current.generation !== viewGeneration) return
        rows.push(...result.data)
        if (result.meta.total > 1000) throw new Error('内容超过 1000 条，请分批编辑排序号')
        if (current >= result.meta.last_page) { setSortRows(rows); return }
      }
      throw new Error('内容数量发生变化，请重新读取')
    })
    if (generation === workflowActions.generation() && view.current.generation === viewGeneration) setSorting(false)
  }
  async function saveSort() {
    if (locked || !sortRows.length) return
    const generation = workflowActions.generation()
    await perform(async () => { const input = { items: sortRows.map(row => ({ id: row.id, expected_version: row.row_version })) };
      if (kind === 'notices') await workflowActions.run('content.notices.sort', input); else await workflowActions.run('content.knowledge.sort', input)
      if (generation !== workflowActions.generation() || view.current.generation !== viewGeneration) return
      setSortRows([]); await refresh() })
  }
  function move(index: number, step: number) { setSortRows(old => { const rows = [...old]; [rows[index], rows[index + step]] = [rows[index + step]!, rows[index]!]; return rows }) }
  const columns: Column<Row>[] = [
    { key: 'title', header: '标题', render: row => <div className="native-row-identity"><strong>{row.title}</strong><small>#{row.id} · 版本 {row.row_version}</small></div> },
    { key: 'status', header: '发布状态', render: row => <span className="support-tag" data-tone={row.show ? 'success' : undefined}>{row.show ? '已启用' : '草稿'}</span> },
    { key: 'scope', header: '可见范围', render: row => <span className="support-tag">{row.audience === 'subscribed' ? '有效订阅用户' : '已登录用户'}</span> },
    { key: 'sort', header: '排序', render: row => row.sort },
    { key: 'actions', header: '操作', render: row => <div className="support-actions"><button className="button" disabled={locked} onClick={() => { loadedDraft.current = ''; setSelected(row.id); setEditing(true); setLocalError('') }}>编辑</button><button className="button" disabled={locked || list.isFetching} onClick={() => void change(row, false)}>{row.show ? '停用' : '启用'}</button><button className="button" disabled={locked || list.isFetching} onClick={() => { if (window.confirm('归档后用户端将无法访问，审计记录仍保留。确定归档？')) void change(row, true) }}>归档</button></div> },
  ]
  return <div className="native-management-page support-page">
    <PageHeader title={kind === 'notices' ? '公告管理' : '知识库管理'} description="管理内容、发布时段、可见范围和排序。" action={<><button className="button" disabled={state.busy || list.isFetching} onClick={() => { setLocalError(''); void refresh() }}><RefreshCw size={15} />刷新</button><button className="button" disabled={locked} onClick={() => { loadedDraft.current = ''; setSelected(''); setDraft(blank()); setEditing(true) }}><Plus size={15} />新增</button></>} />
    {ownPending ? <section className="list-card support-pending" role="status"><h2>操作等待确认</h2><p>上次保存结果尚未确定，请确认后再继续编辑。</p><details className="support-help"><summary>查看操作标识</summary><p>{ownPending.operation} · {ownPending.key}</p></details><button className="button" disabled={state.busy || blocked} onClick={() => void recover()}>使用原操作确认结果</button></section> : null}
    {localError || state.error ? <p role="alert">{localError || managementError(state.error)}</p> : null}
    {blocked ? <p role="alert">当前无法确认内容访问权限，已隐藏数据。</p> : null}
    <ReadList title="内容列表" rows={blocked || list.isError ? [] : list.data?.data ?? []} columns={columns} meta={list.data?.meta} page={page} ready={!!list.data && !blocked} loading={list.isFetching} error={list.isError ? list.error : undefined} onRetry={() => void refresh()} onPage={setPage}>
      <div className="support-heading"><div><h2>{kind === 'notices' ? '公告列表' : '文章列表'}</h2><p>管理发布内容与展示顺序</p></div></div><form className="native-filters" onSubmit={event => { event.preventDefault(); setPage(1); setTitle(search) }}><label>标题<input placeholder="搜索标题" value={search} maxLength={255} onChange={event => setSearch(event.target.value)} /></label><button className="button"><Search size={14} />查询</button><button type="button" className="button" disabled={locked || sorting} onClick={() => void prepareSort()}>{sorting ? '正在读取全量排序…' : '编辑完整排序'}</button></form>
    </ReadList>
    {sortRows.length && !blocked ? <section className="list-card"><h2>完整排序</h2><ol>{sortRows.map((row, index) => <li key={row.id}>{row.title} <button className="button" disabled={locked || index === 0} onClick={() => move(index, -1)}>上移</button><button className="button" disabled={locked || index === sortRows.length - 1} onClick={() => move(index, 1)}>下移</button></li>)}</ol><button className="button" disabled={locked} onClick={() => void saveSort()}>保存排序</button><button className="button" onClick={() => setSortRows([])}>取消</button></section> : null}
    {editing && !blocked ? <section className="list-card"><div className="support-heading"><div><h2>{selected ? '编辑内容' : '新增草稿'}</h2><p>设置正文、可见范围与发布时间</p></div><span className="support-tag" data-tone={draft.show ? 'success' : undefined}>{draft.show ? '启用发布' : '草稿'}</span></div>
      {selected ? <ManagementFeedback error={detail.isError ? detail.error : undefined} loading={detail.isFetching} onRetry={() => void detail.refetch()} /> : null}
      {!selected || detail.data && !detail.isError && !detail.isFetching ? <form className="native-content-form" onSubmit={event => { event.preventDefault(); void save() }}>
        <label>标题<input value={draft.title} maxLength={255} required disabled={locked} onChange={event => update('title', event.target.value)} /></label>
        {kind === 'knowledge' ? <><label>分类<input value={draft.category} maxLength={255} required disabled={locked} onChange={event => update('category', event.target.value)} /></label><label>语言<input value={draft.language} maxLength={32} required disabled={locked} onChange={event => update('language', event.target.value)} /></label></> : <><label>封面 URL<input type="url" value={draft.image} maxLength={2048} disabled={locked} onChange={event => update('image', event.target.value)} /></label><label>标签（英文逗号分隔）<input value={draft.tags} disabled={locked} onChange={event => update('tags', event.target.value)} /></label><label><input type="checkbox" checked={draft.popup} disabled={locked} onChange={event => update('popup', event.target.checked)} />弹窗公告</label></>}
        <label htmlFor={'native-' + kind + '-body'}>正文</label>
        <textarea id={'native-' + kind + '-body'} placeholder="输入正文内容…" rows={12} value={draft.text} maxLength={20000} required disabled={locked} onChange={event => update('text', event.target.value)} />
        <details className="support-help"><summary><Eye size={14} />查看正文预览</summary><div className="support-preview">{draft.text || '暂无正文'}</div><p>此处显示正文文本；最终展示以用户端为准。</p></details><label>可见范围<select value={draft.audience} disabled={locked} onChange={event => update('audience', event.target.value as Draft['audience'])}><option value="authenticated">已登录用户</option><option value="subscribed">有效订阅用户</option></select></label>
        <label>发布时间（留空立即生效）<input type="datetime-local" value={draft.publish} disabled={locked} onChange={event => update('publish', event.target.value)} /></label><label>到期时间（留空长期有效）<input type="datetime-local" value={draft.expire} disabled={locked} onChange={event => update('expire', event.target.value)} /></label>
        <label>排序号<input type="number" min={0} max={2147483647} value={draft.sort} required disabled={locked} onChange={event => update('sort', Number(event.target.value))} /></label><label><input type="checkbox" checked={draft.show} disabled={locked} onChange={event => update('show', event.target.checked)} />启用发布</label>
        <button className="button" disabled={locked}><Save size={14} />保存</button><button className="button" type="button" onClick={() => setEditing(false)}>关闭编辑</button>
      </form> : null}
    </section> : null}
    {!blocked ? <section className="list-card"><div className="support-heading"><div><h2>内容变更记录</h2><p>最近的编辑与发布操作</p></div></div><ManagementFeedback error={audit.isError ? audit.error : undefined} loading={audit.isFetching} onRetry={() => void audit.refetch()} /><ol className="support-timeline">{!audit.isError ? audit.data?.pages.flatMap(result => result.data).map(row => <li key={row.id}>#{row.id} · {row.kind} · {row.operation} · 管理员 {row.actor_id} · {new Date(row.occurred_at * 1000).toLocaleString()} · {row.changes.length} 条记录</li>) : null}</ol>{audit.hasNextPage ? <button className="button" disabled={audit.isFetching} onClick={() => void audit.fetchNextPage()}>继续读取审计</button> : null}</section> : null}
  </div>
}
