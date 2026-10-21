import { t, useLocale } from '../lib/i18n'
import { useMemo } from 'react'
import { mailSample } from './configurationPresentation'

export function MailPreview({ content, subject = '', sample = false }: { content: string; subject?: string; sample?: boolean }) {
  const { locale } = useLocale()
  const document = useMemo(() => '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:; base-uri \'none\'; form-action \'none\'"><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{margin:20px;font-family:system-ui,sans-serif;overflow-wrap:anywhere;color:#20242b}img{max-width:100%;height:auto}table{max-width:100%}</style></head><body>' + (sample ? mailSample(content, true) : content) + '</body></html>', [content, sample, locale])
  return <section className="settings-email-preview" aria-label={t("邮件效果预览")}><div><strong>{t("邮件效果")}</strong><small>{sample ? t("示例数据，仅用于本地预览") : t("变量保留原样")}{t("· 外部图片不加载")}</small></div><p className="settings-preview-subject">{t("主题：")}{sample ? mailSample(subject) : subject}</p>
    <iframe title={t("邮件 HTML 预览")} sandbox="" referrerPolicy="no-referrer" srcDoc={document} tabIndex={-1} />
  </section>
}
