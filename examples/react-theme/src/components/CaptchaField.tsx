// CaptchaField: renders the widget announced by bootstrap (GW-505).
// enabled=true with missing type/siteKey renders a disabled state — the
// caller must block submission, never silently skip verification.
import { useEffect } from 'react'
import type { CaptchaConfig } from '@txboard/theme-sdk'
import { captchaBlocksLogin } from '../api'

export function CaptchaField({ captcha }: { captcha: CaptchaConfig }) {
  useEffect(() => {
    if (!captcha.enabled || !captcha.siteKey) return
    // Provider scripts load here from the official provider CDN; the Gateway
    // only publishes which provider + site key are configured. Read the token
    // from the provider callback and pass it into the login payload.
  }, [captcha])

  if (!captcha.enabled) return null

  return (
    <div className="captcha">
      {captcha.siteKey && captcha.type === 'turnstile' && (
        <div className="cf-turnstile" data-sitekey={captcha.siteKey} />
      )}
      {captcha.siteKey && (captcha.type === 'recaptcha' || captcha.type === 'recaptcha-v3') && (
        <div className="g-recaptcha" data-sitekey={captcha.siteKey} />
      )}
      {captchaBlocksLogin(captcha) && (
        <p className="error" role="alert">CAPTCHA 配置不完整，无法验证。</p>
      )}
    </div>
  )
}
