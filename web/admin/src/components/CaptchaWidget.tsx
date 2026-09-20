import { forwardRef, useEffect, useImperativeHandle, useMemo, useRef, useState } from 'react'
import { captchaSiteKey, createCaptchaController, type CaptchaPayload } from '@txboard/shared'
import type { GuestConfig } from '../api/comm'

export type CaptchaWidgetHandle = {
  getPayload: () => Promise<CaptchaPayload>
  reset: () => void
}

/**
 * React binding for the shared captcha controller.
 *
 * Every provider detail (script loading, widget mounting, token retrieval,
 * reset) lives in `@txboard/shared`; this component only maps it onto React's
 * lifecycle and ref API. The user SPA has an equivalent thin Vue binding, so
 * the two frontends can no longer drift.
 */
export const CaptchaWidget = forwardRef<CaptchaWidgetHandle, { config: GuestConfig | null }>(
  function CaptchaWidget({ config }, ref) {
    const [containerId] = useState(() => 'admin-captcha-' + Math.random().toString(36).slice(2))
    const configRef = useRef<GuestConfig | null>(config)

    const controller = useMemo(
      () =>
        createCaptchaController({
          getConfig: () => configRef.current,
          containerId,
        }),
      [containerId],
    )

    useEffect(() => {
      configRef.current = config
      void controller.mount()
    }, [controller, config])

    useImperativeHandle(
      ref,
      () => ({
        // The admin sign-in flow labels its reCAPTCHA v3 action "login".
        getPayload: () => controller.getPayload({ action: 'login' }),
        reset: () => controller.reset(),
      }),
      [controller],
    )

    // Derived from the prop rather than the controller so a config change is
    // reflected in the same render that delivers it.
    if (!captchaSiteKey(config)) return null
    return (
      <div className="admin-captcha">
        <div id={containerId} />
      </div>
    )
  },
)
