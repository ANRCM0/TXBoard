import { X } from 'lucide-react'
import type { ReactNode } from 'react'

export function Modal({ open, title, children, onClose, wide = false }: { open: boolean; title: string; children: ReactNode; onClose: () => void; wide?: boolean }) {
  if (!open) return null
  return <div className="modal-root" role="dialog" aria-modal="true">
    <button className="modal-backdrop" onClick={onClose} aria-label="关闭" />
    <div className={wide ? 'modal-card modal-card-wide' : 'modal-card'}>
      <div className="modal-header"><h3>{title}</h3><button className="icon-button" onClick={onClose}><X size={18}/></button></div>
      <div className="modal-body">{children}</div>
    </div>
  </div>
}
