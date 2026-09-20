import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, GripVertical, Pencil, Plus, Save, Search, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import {
  deleteKnowledge,
  getKnowledgeAll,
  getKnowledgeCategories,
  getKnowledgeDetail,
  getKnowledgePage,
  saveKnowledge,
  sortKnowledge,
  toggleKnowledge,
  type KnowledgeItem,
} from '../../api/content'
import { MarkdownLite } from '../../components/ui/MarkdownLite'
import { Modal } from '../../components/ui/Modal'
import { PageHeader } from '../../components/ui/PageHeader'

const LANGUAGES = ['zh-CN', 'en-US', 'zh-TW', 'ru-RU'] as const

export function KnowledgeSettingsPage() {
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)
  const [search, setSearch] = useState('')
  const [appliedSearch, setAppliedSearch] = useState('')
  const [category, setCategory] = useState('')
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState<KnowledgeItem>({ show: true, language: 'zh-CN' })
  const [preview, setPreview] = useState(false)
  const [sortMode, setSortMode] = useState(false)
  const [sortRows, setSortRows] = useState<KnowledgeItem[]>([])
  const [dragId, setDragId] = useState<number | null>(null)

  const query = useQuery({
    queryKey: ['knowledge', page, pageSize, appliedSearch, category],
    queryFn: () => getKnowledgePage({
      current: page,
      pageSize,
      title: appliedSearch || undefined,
      category: category || undefined,
    }),
    enabled: !sortMode,
  })

  const categories = useQuery({
    queryKey: ['knowledgeCategories'],
    queryFn: getKnowledgeCategories,
  })

  const invalidate = () => Promise.all([
    qc.invalidateQueries({ queryKey: ['knowledge'] }),
    qc.invalidateQueries({ queryKey: ['knowledgeCategories'] }),
  ])

  const save = useMutation({
    mutationFn: () => saveKnowledge({
      ...form,
      ...(editingId ? { id: editingId } : {}),
    }),
    onSuccess: async () => {
      toast.success(editingId ? '文章已更新' : '文章已创建')
      setDialogOpen(false)
      await invalidate()
    },
  })

  const toggle = useMutation({
    mutationFn: (id: number) => toggleKnowledge(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['knowledge'] }),
  })

  const remove = useMutation({
    mutationFn: (id: number) => deleteKnowledge(id),
    onSuccess: async () => {
      toast.success('文章已删除')
      await invalidate()
    },
  })

  const saveSort = useMutation({
    mutationFn: (ids: number[]) => sortKnowledge(ids),
    onSuccess: async () => {
      toast.success('排序已保存')
      setSortMode(false)
      await invalidate()
    },
  })

  function openCreate() {
    setEditingId(null)
    setForm({ title: '', category: '', language: 'zh-CN', body: '', show: true })
    setPreview(false)
    setDialogOpen(true)
  }

  async function openEdit(row: KnowledgeItem) {
    if (!row.id) return
    const detail = await getKnowledgeDetail(row.id)
    setEditingId(row.id)
    setForm({ ...detail, show: detail.show ?? true })
    setPreview(false)
    setDialogOpen(true)
  }

  async function enterSortMode() {
    const all = await getKnowledgeAll()
    setSortRows(all)
    setSortMode(true)
    setDragId(null)
  }

  function moveDrop(targetId: number) {
    if (dragId === null || dragId === targetId) return
    const next = [...sortRows]
    const from = next.findIndex(item => item.id === dragId)
    const to = next.findIndex(item => item.id === targetId)
    if (from < 0 || to < 0) return
    const moved = next.splice(from, 1)[0]
    next.splice(to, 0, moved)
    setSortRows(next)
    setDragId(null)
  }

  function validateAndSave() {
    if (!String(form.title || '').trim()) {
      toast.error('请输入标题')
      return
    }
    if (!String(form.category || '').trim()) {
      toast.error('请输入分类')
      return
    }
    if (!String(form.body || '').trim()) {
      toast.error('请输入正文')
      return
    }
    save.mutate()
  }

  const rows = sortMode ? sortRows : (query.data?.data || [])

  return <>
    <PageHeader
      title="知识库"
      description="文章分页、搜索、分类、Markdown 编辑、显示控制和全量拖拽排序。"
      action={<div className="actions">
        {sortMode ? <>
          <button className="button" onClick={() => setSortMode(false)}>取消排序</button>
          <button
            className="button primary"
            disabled={saveSort.isPending}
            onClick={() => saveSort.mutate(sortRows.map(item => Number(item.id)).filter(Boolean))}
          >
            <Save size={15}/>{saveSort.isPending ? '保存中…' : '保存排序'}
          </button>
        </> : <>
          <button className="button" onClick={enterSortMode}>编辑排序</button>
          <button className="button primary" onClick={openCreate}><Plus size={15}/>添加文章</button>
        </>}
      </div>}
    />

    {!sortMode && <div className="content-toolbar">
      <div className="order-search">
        <input
          value={search}
          onChange={event => setSearch(event.target.value)}
          onKeyDown={event => {
            if (event.key === 'Enter') {
              setAppliedSearch(search)
              setPage(1)
            }
          }}
          placeholder="搜索标题…"
        />
        <button className="button" onClick={() => { setAppliedSearch(search); setPage(1) }}>
          <Search size={15}/>搜索
        </button>
      </div>

      <select value={category} onChange={event => { setCategory(event.target.value); setPage(1) }}>
        <option value="">全部分类</option>
        {(categories.data || []).map(item => <option key={item} value={item}>{item}</option>)}
      </select>

      {(appliedSearch || category) && <button
        className="button"
        onClick={() => {
          setSearch('')
          setAppliedSearch('')
          setCategory('')
          setPage(1)
        }}
      >清除筛选</button>}
    </div>}

    <div className="card content-table-card">
      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              {sortMode && <th className="drag-col"/>}
              <th>ID</th>
              <th>标题</th>
              <th>分类</th>
              <th>语言</th>
              <th>显示</th>
              <th>更新时间</th>
              {!sortMode && <th>操作</th>}
            </tr>
          </thead>
          <tbody>
            {rows.map(row => <tr
              key={row.id}
              draggable={sortMode}
              className={dragId === row.id ? 'is-dragging' : ''}
              onDragStart={() => row.id && setDragId(row.id)}
              onDragOver={event => sortMode && event.preventDefault()}
              onDrop={() => row.id && moveDrop(row.id)}
            >
              {sortMode && <td className="drag-col"><GripVertical size={17}/></td>}
              <td>{row.id}</td>
              <td><strong>{row.title || '-'}</strong></td>
              <td><span className="badge">{row.category || '-'}</span></td>
              <td>{row.language || '-'}</td>
              <td>
                <button
                  className={Boolean(row.show) ? 'switch-control active' : 'switch-control'}
                  disabled={sortMode}
                  onClick={() => row.id && toggle.mutate(row.id)}
                ><span/></button>
              </td>
              <td>{formatTime(row.updated_at)}</td>
              {!sortMode && <td><div className="actions">
                <button className="icon-button" title="编辑" onClick={() => openEdit(row)}><Pencil size={15}/></button>
                <button
                  className="icon-button danger"
                  title="删除"
                  onClick={() => row.id && confirm('确认删除「' + (row.title || row.id) + '」？') && remove.mutate(row.id)}
                ><Trash2 size={15}/></button>
              </div></td>}
            </tr>)}

            {!rows.length && <tr>
              <td colSpan={sortMode ? 7 : 7} className="empty-cell">
                {query.isLoading ? '加载中…' : '暂无文章'}
              </td>
            </tr>}
          </tbody>
        </table>
      </div>

      {!sortMode && <div className="pagination-bar">
        <span className="pagination-meta">
          共 {query.data?.total || 0} 条 · 第 {query.data?.current_page || page} / {query.data?.last_page || 1} 页
        </span>
        <div className="pagination-actions">
          <select value={pageSize} onChange={event => { setPageSize(Number(event.target.value)); setPage(1) }}>
            <option value={10}>10 / 页</option>
            <option value={20}>20 / 页</option>
            <option value={50}>50 / 页</option>
          </select>
          <button className="icon-button" disabled={page <= 1} onClick={() => setPage(value => Math.max(1, value - 1))}>
            <ChevronLeft size={16}/>
          </button>
          <button
            className="icon-button"
            disabled={page >= Number(query.data?.last_page || 1)}
            onClick={() => setPage(value => value + 1)}
          ><ChevronRight size={16}/></button>
        </div>
      </div>}
    </div>

    <Modal
      open={dialogOpen}
      title={editingId ? '编辑知识库文章' : '添加知识库文章'}
      onClose={() => setDialogOpen(false)}
    >
      <div className="form-stack">
        <label className="field">
          <span>标题 *</span>
          <input value={String(form.title || '')} onChange={event => setForm(current => ({ ...current, title: event.target.value }))}/>
        </label>

        <label className="field">
          <span>分类 *</span>
          <input
            list="knowledge-category-list"
            value={String(form.category || '')}
            onChange={event => setForm(current => ({ ...current, category: event.target.value }))}
          />
          <datalist id="knowledge-category-list">
            {(categories.data || []).map(item => <option key={item} value={item}/>)}
          </datalist>
        </label>

        <label className="field">
          <span>语言 *</span>
          <select
            value={String(form.language || 'zh-CN')}
            onChange={event => setForm(current => ({ ...current, language: event.target.value }))}
          >
            {LANGUAGES.map(item => <option key={item} value={item}>{item}</option>)}
          </select>
        </label>

        <div className="editor-tabs">
          <button className={!preview ? 'tab active' : 'tab'} onClick={() => setPreview(false)}>编辑</button>
          <button className={preview ? 'tab active' : 'tab'} onClick={() => setPreview(true)}>预览</button>
        </div>

        {preview
          ? <MarkdownLite content={String(form.body || '')}/>
          : <label className="field">
            <span>正文 *</span>
            <textarea
              className="knowledge-editor"
              value={String(form.body || '')}
              onChange={event => setForm(current => ({ ...current, body: event.target.value }))}
            />
          </label>}

        <label className="check-field">
          <input
            type="checkbox"
            checked={Boolean(form.show)}
            onChange={event => setForm(current => ({ ...current, show: event.target.checked }))}
          />
          <span>前台显示</span>
        </label>

        <div className="card-actions">
          <button className="button" onClick={() => setDialogOpen(false)}>取消</button>
          <button className="button primary" disabled={save.isPending} onClick={validateAndSave}>
            {save.isPending ? '保存中…' : '保存'}
          </button>
        </div>
      </div>
    </Modal>
  </>
}

function formatTime(value: unknown) {
  const n = Number(value)
  if (Number.isFinite(n) && n > 0) {
    return new Date(n < 10_000_000_000 ? n * 1000 : n).toLocaleString()
  }
  if (typeof value === 'string' && value) {
    const parsed = Date.parse(value)
    if (Number.isFinite(parsed)) return new Date(parsed).toLocaleString()
  }
  return '-'
}
