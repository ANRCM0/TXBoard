import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, GripVertical, Pencil, Plus, Save, Search, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import {
  deleteNotice,
  getNoticeAll,
  getNoticePage,
  saveNotice,
  sortNotice,
  toggleNotice,
  type NoticeItem,
} from '../../api/content'
import { Modal } from '../../components/ui/Modal'
import { PageHeader } from '../../components/ui/PageHeader'
import { requestConfirm } from '../../components/ui/ConfirmDialog'

export function NoticeSettingsPage() {
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)
  const [search, setSearch] = useState('')
  const [appliedSearch, setAppliedSearch] = useState('')
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState<NoticeItem>({ show: 1, popup: 0, tags: [] })
  const [tagDraft, setTagDraft] = useState('')
  const [sortMode, setSortMode] = useState(false)
  const [sortRows, setSortRows] = useState<NoticeItem[]>([])
  const [dragId, setDragId] = useState<number | null>(null)
  const [imgError, setImgError] = useState('')

  const query = useQuery({
    queryKey: ['notices', page, pageSize, appliedSearch],
    queryFn: () => getNoticePage({
      current: page,
      pageSize,
      title: appliedSearch || undefined,
    }),
    enabled: !sortMode,
  })

  const invalidate = () => qc.invalidateQueries({ queryKey: ['notices'] })

  const save = useMutation({
    mutationFn: () => saveNotice({
      ...form,
      ...(editingId ? { id: editingId } : {}),
      tags: normalizeTags(tagDraft),
    }),
    onSuccess: async () => {
      toast.success(editingId ? '公告已更新' : '公告已创建')
      setDialogOpen(false)
      await invalidate()
    },
  })

  const toggle = useMutation({
    mutationFn: (id: number) => toggleNotice(id),
    onSuccess: () => invalidate(),
  })

  const remove = useMutation({
    mutationFn: (id: number) => deleteNotice(id),
    onSuccess: async () => {
      toast.success('公告已删除')
      await invalidate()
    },
  })

  const saveSort = useMutation({
    mutationFn: (ids: number[]) => sortNotice(ids),
    onSuccess: async () => {
      toast.success('排序已保存')
      setSortMode(false)
      await invalidate()
    },
  })

  function openCreate() {
    setEditingId(null)
    setForm({
      show: 1,
      popup: 0,
      title: '',
      content: '',
      img_url: '',
      tags: [],
    })
    setTagDraft('')
    setDialogOpen(true)
  }

  function openEdit(row: NoticeItem) {
    setEditingId(row.id || null)
    setForm({ ...row, tags: normalizeTags(row.tags) })
    setTagDraft(normalizeTags(row.tags).join(', '))
    setDialogOpen(true)
  }

  async function enterSortMode() {
    try {
      const all = await getNoticeAll()
      setSortRows(all)
      setSortMode(true)
      setDragId(null)
    } catch {}
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
      toast.error('请输入公告标题')
      return
    }
    if (!String(form.content || '').trim()) {
      toast.error('请输入公告内容')
      return
    }
    save.mutate()
  }

  const rows = sortMode ? sortRows : (query.data?.data || [])

  return <>
    <PageHeader
      title="公告管理"
      description="公告分页、搜索、弹窗控制、标签、图片、显示开关和拖拽排序。"
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
          <button className="button primary" onClick={openCreate}><Plus size={15}/>添加公告</button>
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
          placeholder="搜索公告标题…"
        />
        <button className="button" onClick={() => { setAppliedSearch(search); setPage(1) }}>
          <Search size={15}/>搜索
        </button>
      </div>

      {appliedSearch && <button
        className="button"
        onClick={() => {
          setSearch('')
          setAppliedSearch('')
          setPage(1)
        }}
      >清除</button>}
    </div>}

    <div className="card content-table-card">
      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              {sortMode && <th className="drag-col"/>}
              <th>ID</th>
              <th>显示</th>
              <th>标题</th>
              <th>标签</th>
              <th>弹窗</th>
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
              <td>
                <button
                  className={Boolean(row.show) ? 'switch-control active' : 'switch-control'}
                  disabled={sortMode}
                  onClick={() => row.id && toggle.mutate(row.id)}
                ><span/></button>
              </td>
              <td><strong>{row.title || '-'}</strong></td>
              <td>
                <div className="notice-tags">
                  {normalizeTags(row.tags).slice(0, 4).map((tag, index) => <span className="badge" key={tag + index}>{tag}</span>)}
                </div>
              </td>
              <td>
                <span className={Boolean(row.popup) ? 'status ok' : 'status off'}>
                  {row.popup ? '是' : '否'}
                </span>
              </td>
              {!sortMode && <td><div className="actions">
                <button className="icon-button" title="编辑" onClick={() => openEdit(row)}><Pencil size={15}/></button>
                <button
                  className="icon-button danger"
                  title="删除"
                  onClick={() => { const id = row.id; if (id === undefined) return; requestConfirm({ title: '删除公告', message: '确认删除「' + (row.title || row.id) + '」？', danger: true, confirmLabel: '删除', action: () => remove.mutate(id) }) }}
                ><Trash2 size={15}/></button>
              </div></td>}
            </tr>)}

            {!rows.length && <tr>
              <td colSpan={sortMode ? 6 : 6} className="empty-cell">
                {query.isLoading ? '加载中…' : '暂无公告'}
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
      title={editingId ? '编辑公告' : '添加公告'}
      subtitle="维护公告正文、标签、图片和前台展示方式。"
      className="content-editor-modal"
      onClose={() => setDialogOpen(false)}
      footer={
        <div className="content-editor-footer">
          <button className="button" onClick={() => setDialogOpen(false)} disabled={save.isPending}>取消</button>
          <button className="button primary" disabled={save.isPending} onClick={validateAndSave}>
            {save.isPending ? '保存中…' : '保存公告'}
          </button>
        </div>
      }
    >
      <div className="content-editor-form">
        <section className="admin-form-section">
          <div className="admin-form-section-head">
            <div><strong>基本信息</strong><small>标题、标签与可选的展示图片。</small></div>
          </div>
          <div className="admin-form-section-body">
            <div className="content-editor-grid">
              <label className="field full">
                <span>标题 *</span>
                <input
                  autoFocus
                  value={String(form.title || '')}
                  onChange={event => setForm(current => ({ ...current, title: event.target.value }))}
                  placeholder="例如：服务维护通知"
                />
              </label>

              <label className="field">
                <span>图片 URL</span>
                <input
                  value={String(form.img_url || '')}
                  onChange={event => setForm(current => ({ ...current, img_url: event.target.value }))}
                  placeholder="https://..."
                />
                <small className="field-help">留空表示不展示图片。</small>
              </label>

              <label className="field">
                <span>标签</span>
                <input
                  value={tagDraft}
                  onChange={event => setTagDraft(event.target.value)}
                  onBlur={() => setForm(current => ({ ...current, tags: normalizeTags(tagDraft) }))}
                  placeholder="维护, 重要, 活动"
                />
                <small className="field-help">使用逗号分隔多个标签。</small>
              </label>

              {form.img_url && imgError !== form.img_url ? (
                <div className="notice-image-preview full">
                  <img
                    src={String(form.img_url)}
                    alt="公告预览"
                    onError={() => setImgError(String(form.img_url))}
                  />
                </div>
              ) : null}
            </div>
          </div>
        </section>

        <section className="admin-form-section">
          <div className="admin-form-section-head">
            <div><strong>公告正文</strong><small>正文保持纯文本输出，避免编辑器引入额外格式语义。</small></div>
          </div>
          <div className="admin-form-section-body">
            <label className="field">
              <span>正文 *</span>
              <textarea
                className="notice-editor"
                value={String(form.content || '')}
                onChange={event => setForm(current => ({ ...current, content: event.target.value }))}
                placeholder="填写要展示给用户的公告内容…"
              />
            </label>
          </div>
        </section>

        <section className="admin-form-section">
          <div className="admin-form-section-head">
            <div><strong>展示方式</strong><small>控制公告是否可见，以及是否以弹窗形式展示。</small></div>
          </div>
          <div className="admin-form-section-body">
            <div className="two-col compact">
              <label className="check-field">
                <input
                  type="checkbox"
                  checked={Boolean(form.show)}
                  onChange={event => setForm(current => ({ ...current, show: event.target.checked ? 1 : 0 }))}
                />
                <span><strong>前台显示</strong><small>关闭后公告保留但不展示。</small></span>
              </label>
              <label className="check-field">
                <input
                  type="checkbox"
                  checked={Boolean(form.popup)}
                  onChange={event => setForm(current => ({ ...current, popup: event.target.checked ? 1 : 0 }))}
                />
                <span><strong>弹窗展示</strong><small>启用后按现有用户端规则弹出。</small></span>
              </label>
            </div>
          </div>
        </section>
      </div>
    </Modal>
  </>
}

function normalizeTags(value: NoticeItem['tags']) {
  if (Array.isArray(value)) return value.map(String).map(item => item.trim()).filter(Boolean)
  if (typeof value === 'string') return value.split(',').map(item => item.trim()).filter(Boolean)
  return []
}
