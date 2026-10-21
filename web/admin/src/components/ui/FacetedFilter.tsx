import { t, useLocale } from '../../lib/i18n'
import { useId, useState } from 'react'
import { Filter } from 'lucide-react'
import { Popover } from './Popover'
import './faceted-filter.css'

export type FacetedFilterOption = { value: string; label: string; count?: number }

export function FacetedFilter({ label, options, value, onChange, disabled, multiple = true }: {
  label: string
  options: readonly FacetedFilterOption[]
  value: readonly string[]
  onChange: (value: string[]) => void
  disabled?: boolean
  multiple?: boolean
}) {
  useLocale()
  const group = useId()
  const [search, setSearch] = useState('')
  const selected = new Set(value)
  const visible = options.filter(option => option.label.toLowerCase().includes(search.trim().toLowerCase()))
  const selectedLabel = !multiple && value.length ? options.find(option => option.value === value[0])?.label : undefined
  function toggle(option: FacetedFilterOption) {
    if (!multiple) { onChange(selected.has(option.value) ? [] : [option.value]); return }
    onChange(selected.has(option.value) ? value.filter(item => item !== option.value) : [...value, option.value])
  }
  return <fieldset className="faceted-filter" disabled={disabled}>
    <Popover label={t("{label}筛选", { label })} panelClassName="faceted-filter-panel" triggerClassName="button"
      closeOnSelect={!multiple} align="start"
      trigger={<><Filter size={14} aria-hidden="true" />{label}{selectedLabel ?? (value.length ? <span className="faceted-filter-count">{value.length}</span> : null)}</>}>
      <label className="faceted-filter-search"><span>{t("搜索{label}", { label })}</span><input className="ui-input" aria-label={t("搜索{label}筛选", { label })} value={search}
        onChange={event => setSearch(event.target.value)} placeholder={t("输入名称筛选选项")} /></label>
      <div className="faceted-filter-options" role="group" aria-label={t("{label}选项", { label })}>
        {visible.map(option => <label key={option.value}>
          <input type={multiple ? 'checkbox' : 'radio'} name={multiple ? undefined : group} checked={selected.has(option.value)}
            onChange={() => toggle(option)} />
          <span>{option.label}</span>{option.count !== undefined ? <small>{option.count}</small> : null}
        </label>)}
        {!visible.length ? <p>{t("没有匹配的选项")}</p> : null}
      </div>
      {value.length ? <button type="button" className="button" onClick={() => onChange([])}>{t("清除此项筛选")}</button> : null}
    </Popover>
  </fieldset>
}
