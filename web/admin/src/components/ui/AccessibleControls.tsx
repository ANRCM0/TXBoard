import type { ButtonHTMLAttributes, ReactNode } from 'react'
import './AccessibleControls.css'

type IconActionProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  label: string
  children: ReactNode
}

/** Icon-only actions must have a reliable name for assistive technology. */
export function IconAction({ label, children, className = '', ...props }: IconActionProps) {
  return <button
    {...props}
    type="button"
    className={['icon-button', className].filter(Boolean).join(' ')}
    aria-label={label}
    title={props.title ?? label}
  >{children}</button>
}

type SettingSwitchProps = {
  label: string
  checked: boolean
  disabled?: boolean
  onChange: (checked: boolean) => void
}

/** Semantic switch with an extended hit target around its compact track. */
export function SettingSwitch({ label, checked, disabled = false, onChange }: SettingSwitchProps) {
  return <button
    type="button"
    className="tx-setting-switch"
    role="switch"
    aria-label={label}
    aria-checked={checked}
    disabled={disabled}
    onClick={() => onChange(!checked)}
  >
    <span className="tx-setting-switch-track" aria-hidden="true">
      <span className="tx-setting-switch-thumb" />
    </span>
  </button>
}
