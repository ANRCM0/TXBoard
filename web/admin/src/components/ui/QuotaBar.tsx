import { t, useLocale, formatNumber } from '../../lib/i18n'
import { gigabytes, toByteString } from '../../lib/format'

function validBytes(value: unknown): value is bigint | string {
  return typeof value === 'bigint' || (typeof value === 'string' && /^(0|[1-9][0-9]{0,39})$/.test(value))
}

export function QuotaBar({ used, total, label = t("已用配额"), format = gigabytes }: {
  used?: bigint | string
  total?: bigint | string
  label?: string
  format?: (value: bigint) => string
}) {
  useLocale()
  if (!validBytes(used) || !validBytes(total)) {
    return <div className="ui-quota">
      <div className="ui-quota-head"><span>{t(label)}</span><strong className="is-unknown">{t("未上报")}</strong></div>
      <p className="ui-quota-unknown">{t("配额或已用流量暂不可用")}</p>
    </div>
  }
  const usedBytes = toByteString(used)
  const totalBytes = toByteString(total)
  const basisPoints = totalBytes > 0n
    ? usedBytes >= totalBytes ? 10_000n : usedBytes * 10_000n / totalBytes
    : 0n
  const percent = Number(basisPoints) / 100
  const tone = percent >= 100 ? 'danger' : percent >= 80 ? 'warning' : 'success'
  const remaining = totalBytes > usedBytes ? totalBytes - usedBytes : 0n

  return <div className="ui-quota">
    <div className="ui-quota-head">
      <span>{t(label)}</span>
      <strong className={'is-' + tone}>{formatNumber(percent, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%</strong>
    </div>
    <div className="ui-quota-track" role="progressbar" aria-label={t(label)}
      aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(percent)}>
      <span className={'ui-quota-fill is-' + tone} style={{ width: percent + '%' }} />
    </div>
    <div className="ui-quota-meta">
      <span>{format(usedBytes)} / {format(totalBytes)}</span>
      <span>{t('剩余 {amount}', { amount: format(remaining) })}</span>
    </div>
  </div>
}
