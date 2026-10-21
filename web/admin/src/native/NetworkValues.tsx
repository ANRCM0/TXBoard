import { t, useLocale } from '../lib/i18n'
import { gigabytes } from '../lib/format'
import { CopyButton } from '../components/ui/CopyButton'
import './network-values.css'

export function NetworkTrafficSplit({ upload, download }: { upload: string | null; download: string | null }) {
  useLocale()
  if (upload === null && download === null) return <span className="network-muted">{t("未上报")}</span>
  const up = upload === null ? 0n : BigInt(upload)
  const down = download === null ? 0n : BigInt(download)
  const total = up + down
  const percent = total > 0n ? Number(up * 10000n / total) / 100 : 50
  return <div className="network-traffic-split">
    <div className="network-traffic-split-values">
      <span><small>{t("上传")}</small><NetworkBytes value={upload} /></span>
      <span><small>{t("下载")}</small><NetworkBytes value={download} /></span>
    </div>
    <div className="network-traffic-split-bar" aria-hidden="true">
      <span className="is-upload" style={{ width: percent + '%' }} />
      <span className="is-download" />
    </div>
  </div>
}

export function NetworkBytes({ value }: { value: string | null }) {
  useLocale()
  if (value === null) return <span className="network-muted">{t("未上报")}</span>
  const bytes = BigInt(value)
  const divisor = bytes >= 1_000_000n ? 1_000_000n : bytes >= 1_000n ? 1_000n : 1n
  const hundredths = (bytes * 100n + divisor / 2n) / divisor
  const readable = bytes >= 1_000_000_000n ? gigabytes(bytes) : divisor === 1n ? value + ' B'
    : `${hundredths / 100n}.${String(hundredths % 100n).padStart(2, '0')} ${divisor === 1_000_000n ? 'MB' : 'KB'}`
  return <code title={t("{value} 字节", { value })}>{readable}</code>
}

export function NetworkEndpoint({ host, port }: { host: string; port: string | number }) {
  useLocale()
  const endpoint = (host.includes(':') && !host.startsWith('[') ? '[' + host + ']' : host) + ':' + port
  return <span className="network-copy-value"><code>{endpoint}</code><CopyButton value={endpoint} label={t("复制连接地址")} /></span>
}
