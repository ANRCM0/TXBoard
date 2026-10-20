import { t, useLocale } from '../lib/i18n'
import { useState } from 'react'
import { Filter } from 'lucide-react'
import { Popover } from '../components/ui/Popover'
import './network-facets.css'

export type NetworkFacetOption = { value: string; label: string; count?: number }

export function NetworkFacet({ label, options, value, onChange, disabled }: {
  label: string; options: readonly NetworkFacetOption[]; value: readonly string[]
  onChange: (value: string[]) => void; disabled?: boolean
}) {
  useLocale()
  const [search, setSearch] = useState('')
  const visible = options.filter(option => option.label.toLowerCase().includes(search.trim().toLowerCase()))
  return <fieldset className="network-facet" disabled={disabled}>
    <Popover label={t("{label}筛选", { label })} panelClassName="network-facet-panel" triggerClassName="button"
      trigger={<><Filter size={14} aria-hidden="true" />{label}{value.length ? <span className="network-facet-count">{value.length}</span> : null}</>}>
      <label className="network-facet-search"><span>{t("搜索{label}", { label })}</span><input className="ui-input" aria-label={t("搜索{label}筛选", { label })} value={search} onChange={event => setSearch(event.target.value)} placeholder={t("输入名称筛选选项")} /></label>
      <div className="network-facet-options" role="group" aria-label={t("{label}选项", { label })}>
        {visible.map(option => <label key={option.value}><input type="checkbox" checked={value.includes(option.value)}
          onChange={event => onChange(event.target.checked ? [...value, option.value] : value.filter(item => item !== option.value))} />
          <span>{option.label}</span>{option.count !== undefined ? <small>{option.count}</small> : null}</label>)}
        {!visible.length ? <p>{t("没有匹配的选项")}</p> : null}
      </div>
      {value.length ? <button type="button" className="button" onClick={() => onChange([])}>{t("清除此项筛选")}</button> : null}
    </Popover>
  </fieldset>
}
