import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  CaptchaUnavailableError,
  captchaRenderKey,
  captchaSiteKey,
  captchaType,
  createCaptchaController,
  type CaptchaConfig,
} from './captcha'

const turnstileConfig: CaptchaConfig = {
  is_captcha: 1,
  captcha_type: 'turnstile',
  turnstile_site_key: 'turnstile-key',
}

const recaptchaConfig: CaptchaConfig = {
  is_captcha: 1,
  captcha_type: 'recaptcha',
  recaptcha_site_key: 'recaptcha-key',
}

const v3Config: CaptchaConfig = {
  is_captcha: 1,
  captcha_type: 'recaptcha-v3',
  recaptcha_v3_site_key: 'v3-key',
}

/**
 * jsdom never fires a script's `load` event, so pre-register the tag the
 * provider would have injected; loadScript() then resolves immediately.
 */
function preloadScript(id: string) {
  const script = document.createElement('script')
  script.id = id
  document.head.appendChild(script)
}

function host(id = 'captcha-host') {
  const element = document.createElement('div')
  element.id = id
  document.body.appendChild(element)
  return element
}

afterEach(() => {
  delete (window as { turnstile?: unknown }).turnstile
  delete (window as { grecaptcha?: unknown }).grecaptcha
  document.head.innerHTML = ''
  document.body.innerHTML = ''
})

describe('captcha selection helpers', () => {
  it('selects the site key for the configured provider', () => {
    expect(captchaSiteKey(turnstileConfig)).toBe('turnstile-key')
    expect(captchaSiteKey(recaptchaConfig)).toBe('recaptcha-key')
    expect(captchaSiteKey(v3Config)).toBe('v3-key')
  })

  it('renders nothing while captcha is disabled or the key is missing', () => {
    expect(captchaSiteKey({ ...turnstileConfig, is_captcha: 0 })).toBe('')
    expect(captchaSiteKey({ is_captcha: 1, captcha_type: 'turnstile' })).toBe('')
    expect(captchaSiteKey(null)).toBe('')
  })

  it('defaults to reCAPTCHA when no type is configured', () => {
    expect(captchaType({ is_captcha: 1 })).toBe('recaptcha')
    expect(captchaType(null)).toBe('recaptcha')
  })

  it('changes the render key when the provider or key changes', () => {
    expect(captchaRenderKey(turnstileConfig)).toBe('turnstile:turnstile-key')
    expect(captchaRenderKey(recaptchaConfig)).toBe('recaptcha:recaptcha-key')
    expect(captchaRenderKey({ ...turnstileConfig, turnstile_site_key: 'other' })).not.toBe(
      captchaRenderKey(turnstileConfig),
    )
    expect(captchaRenderKey({ ...turnstileConfig, is_captcha: 0 })).toBeNull()
  })
})

describe('createCaptchaController', () => {
  it('mounts a Turnstile widget once per config and exposes its token', async () => {
    preloadScript('tx-cf-turnstile')
    host()
    const render = vi.fn((_target: string | HTMLElement, _options: Record<string, unknown>) => 'widget-1')
    const getResponse = vi.fn(() => 'token-1')
    const reset = vi.fn()
    ;(window as { turnstile?: unknown }).turnstile = { render, getResponse, reset }

    const controller = createCaptchaController({
      getConfig: () => turnstileConfig,
      containerId: 'captcha-host',
    })

    await controller.mount()
    await controller.mount()
    await controller.getPayload()

    expect(render).toHaveBeenCalledTimes(1)
    expect(render.mock.calls[0][1]).toMatchObject({ sitekey: 'turnstile-key' })
    await expect(controller.getPayload()).resolves.toEqual({ turnstile_token: 'token-1' })

    controller.reset()
    expect(reset).toHaveBeenCalledWith('widget-1')
  })

  it('re-mounts when the configured site key changes', async () => {
    preloadScript('tx-cf-turnstile')
    host()
    const render = vi.fn((_target: string | HTMLElement, _options: Record<string, unknown>) => 'widget-1')
    ;(window as { turnstile?: unknown }).turnstile = { render, getResponse: () => '', reset: vi.fn() }

    let config: CaptchaConfig = turnstileConfig
    const controller = createCaptchaController({
      getConfig: () => config,
      containerId: 'captcha-host',
    })

    await controller.mount()
    config = { ...turnstileConfig, turnstile_site_key: 'rotated' }
    await controller.mount()

    expect(render).toHaveBeenCalledTimes(2)
  })

  it('fails closed when a v3 token cannot be produced', async () => {
    preloadScript('tx-google-recaptcha-v3')
    host()
    ;(window as { grecaptcha?: unknown }).grecaptcha = {
      ready: (callback: () => void) => callback(),
      execute: () => Promise.reject(new Error('blocked')),
      reset: vi.fn(),
      getResponse: () => '',
    }

    const controller = createCaptchaController({
      getConfig: () => v3Config,
      containerId: 'captcha-host',
    })
    await controller.mount()

    // There is deliberately no client-side "skip" payload: the browser must not
    // be able to opt out of captcha, so the payload call throws instead.
    await expect(controller.getPayload({ action: 'login' })).rejects.toBeInstanceOf(
      CaptchaUnavailableError,
    )
  })

  it('returns an empty payload when captcha is disabled', async () => {
    const controller = createCaptchaController({
      getConfig: () => ({ ...turnstileConfig, is_captcha: 0 }),
      containerId: 'captcha-host',
    })

    await expect(controller.getPayload()).resolves.toEqual({})
    expect(controller.isActive()).toBe(false)
  })
})
