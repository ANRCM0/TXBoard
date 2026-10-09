import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { api, nativeApi, saveAuthData } from './client'
import { fetchInvite, generateInviteCode } from './invite'

const previousNative = nativeApi.defaults.adapter
const previousLegacy = api.defaults.adapter
const calls: string[] = []

beforeEach(() => {
  calls.length = 0
  localStorage.clear()
  saveAuthData('Bearer invitation-test')
  nativeApi.defaults.adapter = async config => {
    calls.push(`${config.method} ${config.url}`)
    return {
      data: {
        request_id: 'invitation-test',
        data: config.method === 'post' ? true : { codes: [], stat: [0, 0, 0, 10, 0] },
      },
      status: 200,
      statusText: 'OK',
      headers: {},
      config,
    } as never
  }
  api.defaults.adapter = async () => { throw new Error('Unexpected legacy invitation call') }
})

afterEach(() => {
  nativeApi.defaults.adapter = previousNative
  api.defaults.adapter = previousLegacy
  localStorage.clear()
})

describe('native invitations', () => {
  it('loads invitation counters from TXAPI', async () => {
    expect(await fetchInvite()).toEqual({ codes: [], stat: [0, 0, 0, 10, 0] })
    expect(calls).toEqual(['get /invites'])
  })
  it('generates codes with POST', async () => {
    expect(await generateInviteCode()).toBe(true)
    expect(calls).toEqual(['post /invites'])
  })
})
