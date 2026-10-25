import {
  cloneElement,
  isValidElement,
  useId,
  type InputHTMLAttributes,
  type ReactElement,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
} from 'react'

type ControlProps = {
  id?: string
  required?: boolean
  'aria-invalid'?: boolean
  'aria-describedby'?: string
  'aria-errormessage'?: string
}

export function Field({ label, hint, error, required, className = '', children }: {
  label: string
  hint?: ReactNode
  error?: ReactNode
  required?: boolean
  className?: string
  children: ReactNode
}) {
  const generatedId = useId()
  const childProps = isValidElement(children) ? children.props as ControlProps : undefined
  const controlId = childProps?.id ?? generatedId
  const hintId = hint ? controlId + '-hint' : undefined
  const errorId = error ? controlId + '-error' : undefined
  const isRequired = required || childProps?.required
  const describedBy = [childProps?.['aria-describedby'], hintId, errorId].filter(Boolean).join(' ') || undefined
  const control = isValidElement(children)
    ? cloneElement(children as ReactElement<ControlProps>, {
        id: controlId,
        required: isRequired,
        'aria-invalid': error ? true : childProps?.['aria-invalid'],
        'aria-describedby': describedBy,
        'aria-errormessage': errorId || childProps?.['aria-errormessage'],
      })
    : children

  return <div className={'field ui-field ' + className}>
    <label className="ui-field-label" htmlFor={controlId}>{label}{isRequired ? <b className="ui-required" aria-hidden="true"> *</b> : null}</label>
    {control}
    {hintId ? <small id={hintId} className="field-help">{hint}</small> : null}
    {errorId ? <small id={errorId} className="field-error" role="alert">{error}</small> : null}
  </div>
}

export function Input({ className = '', ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return <input {...props} className={'ui-input ' + className} />
}

export function Select({ className = '', ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
  return <select {...props} className={'ui-select ' + className} />
}

export function Textarea({ className = '', ...props }: TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return <textarea {...props} className={'ui-textarea ' + className} />
}

export function Checkbox({ label, className = '', ...props }: InputHTMLAttributes<HTMLInputElement> & {
  label: ReactNode
}) {
  return <label className={'ui-checkbox ' + className}>
    <input type="checkbox" {...props} />
    <span>{label}</span>
  </label>
}

export function Switch({ label, className = '', ...props }: InputHTMLAttributes<HTMLInputElement> & {
  label: ReactNode
}) {
  return <label className={'ui-switch ' + className}>
    <input type="checkbox" role="switch" {...props} />
    <span className="ui-switch-track" aria-hidden="true" />
    <span>{label}</span>
  </label>
}
