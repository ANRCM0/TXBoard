import { Copy, MoreHorizontal, Pencil, Trash2 } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import type { NodeItem } from '../../api/server'

/** Portaled actions can appear above a horizontally scrollable node table. */
export function NodeRowActions({node, pending, onEdit, onCopy, onDelete}: {
  node: NodeItem
  pending: boolean
  onEdit: () => void
  onCopy: () => void
  onDelete: () => void
}) {
  const [open, setOpen] = useState(false)
  const [coords, setCoords] = useState({top:0,left:0})
  const triggerRef = useRef<HTMLButtonElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)

  const openMenu = () => {
    if (open) { setOpen(false); return }
    const rect = triggerRef.current?.getBoundingClientRect()
    if (!rect) return
    const width = 190
    const height = 136
    const left = Math.max(8, Math.min(window.innerWidth - width - 8, rect.right - width))
    const top = rect.bottom + height + 8 <= window.innerHeight
      ? rect.bottom + 5
      : Math.max(8, rect.top - height - 5)
    setCoords({left,top})
    setOpen(true)
  }

  useEffect(() => {
    if (!open) return
    menuRef.current?.querySelector<HTMLButtonElement>('button:not(:disabled)')?.focus()
    const onPointer = (event: PointerEvent) => {
      if (menuRef.current?.contains(event.target as Node) ||
        triggerRef.current?.contains(event.target as Node)) return
      setOpen(false)
    }
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        setOpen(false)
        triggerRef.current?.focus()
      }
      if (!['ArrowDown','ArrowUp','Home','End'].includes(event.key)) return
      if (!menuRef.current?.contains(document.activeElement)) return
      const buttons = Array.from(menuRef.current.querySelectorAll<HTMLButtonElement>('button:not(:disabled)'))
      const index = buttons.indexOf(document.activeElement as HTMLButtonElement)
      if (!buttons.length) return
      event.preventDefault()
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1
        : (index + (event.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length
      buttons[next]?.focus()
    }
    const closeOnScroll = () => setOpen(false)
    document.addEventListener('pointerdown',onPointer)
    document.addEventListener('keydown',onKey)
    window.addEventListener('scroll',closeOnScroll,true)
    window.addEventListener('resize',closeOnScroll)
    return () => {
      document.removeEventListener('pointerdown',onPointer)
      document.removeEventListener('keydown',onKey)
      window.removeEventListener('scroll',closeOnScroll,true)
      window.removeEventListener('resize',closeOnScroll)
    }
  }, [open])

  const act = (action: () => void) => { setOpen(false); action() }

  return <>
    <button type="button" className="nodes-v3-action-trigger" ref={triggerRef}
      aria-label={'节点 ' + (node.name || node.id) + ' 的操作'}
      aria-haspopup="menu" aria-expanded={open} onClick={openMenu}>
      <MoreHorizontal size={18} aria-hidden="true"/>
    </button>
    {open ? createPortal(
      <div className="nodes-v3-menu" role="menu" aria-label={'节点 ' + (node.name || node.id) + ' 的操作菜单'}
        ref={menuRef} style={{position:'fixed',top:coords.top,left:coords.left}}>
        <button type="button" role="menuitem" onClick={() => act(onEdit)}>
          <Pencil size={15} aria-hidden="true"/>编辑
        </button>
        <button type="button" role="menuitem" disabled={pending} onClick={() => act(onCopy)}>
          <Copy size={15} aria-hidden="true"/>复制
        </button>
        <button type="button" role="menuitem" className="danger" disabled={pending}
          onClick={() => act(onDelete)}><Trash2 size={15} aria-hidden="true"/>删除</button>
      </div>, document.body
    ) : null}
  </>
}
