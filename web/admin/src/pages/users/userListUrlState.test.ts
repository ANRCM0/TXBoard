import { describe, expect, it } from 'vitest'
import { defaultUserListState, readUserListState, writeUserListState } from './userListUrlState'

describe('user management URL state', () => {
  it('hydrates supported filters and valid pagination / sorting', () => {
    expect(readUserListState(new URLSearchParams('q=test%40example.com&plan=7&status=0&sort=balance&desc=0&page=3&size=50')))
      .toEqual({ page:3, pageSize:50, email:'test@example.com', planId:'7', banState:'0', sortField:'balance', sortDesc:false })
  })

  it('rejects malformed or unsupported values', () => {
    const state = readUserListState(new URLSearchParams('page=-4&size=999&q=+abc+&plan=foo&status=5&sort=secret&desc=1'))
    expect(state).toEqual({...defaultUserListState, email:'abc'})
    expect(readUserListState(new URLSearchParams('page=9007199254740992')).page).toBe(1)
    expect(readUserListState(new URLSearchParams('page=0&plan=0')).planId).toBe('')
  })

  it('writes canonical short parameters while retaining unrelated values', () => {
    const next = writeUserListState(new URLSearchParams('tracking=1&page=9&sort=invalid'),
      {...defaultUserListState, email:'  hello@example.com ', page:2, pageSize:20, banState:'1'})
    expect(next.get('tracking')).toBe('1')
    expect(next.get('page')).toBe('2')
    expect(next.get('size')).toBe('20')
    expect(next.get('q')).toBe('hello@example.com')
    expect(next.get('status')).toBe('1')
    expect(next.has('sort')).toBe(false)
    expect(writeUserListState(next, defaultUserListState).get('page')).toBeNull()
  })
})
