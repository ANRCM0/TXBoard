/** URL is the canonical state of user-list filters, sorting and pagination. */
export type UserListState = {
  page: number
  pageSize: 10 | 20 | 50 | 100
  email: string
  planId: string
  banState: '' | '0' | '1'
  sortField: 'id' | 'email' | 'balance' | 'total_used' | 'expired_at' | 'created_at'
  sortDesc: boolean
}

const sortFields = ['id', 'email', 'balance', 'total_used', 'expired_at', 'created_at'] as const
const pageSizes = [10, 20, 50, 100] as const

export const defaultUserListState: UserListState = {
  page: 1,
  pageSize: 10,
  email: '',
  planId: '',
  banState: '',
  sortField: 'id',
  sortDesc: true,
}

function readPage(value: string | null) {
  if (!value || !/^[1-9][0-9]*$/.test(value)) return 1
  const parsed = Number(value)
  return Number.isSafeInteger(parsed) ? parsed : 1
}

export function readUserListState(params: URLSearchParams): UserListState {
  const pageSize = Number(params.get('size'))
  const sort = params.get('sort')
  const plan = params.get('plan') ?? ''
  const status = params.get('status')
  return {
    page: readPage(params.get('page')),
    pageSize: pageSizes.find(item => item === pageSize) ?? 10,
    email: params.get('q')?.trim() ?? '',
    planId: /^[1-9][0-9]*$/.test(plan) && Number.isSafeInteger(Number(plan)) ? plan : '',
    banState: status === '0' || status === '1' ? status : '',
    sortField: sortFields.find(item => item === sort) ?? 'id',
    sortDesc: params.get('desc') !== '0',
  }
}

/** Preserve unrelated query params, omit defaults and reject arbitrary sort/filter keys. */
export function writeUserListState(current: URLSearchParams, state: UserListState) {
  const next = new URLSearchParams(current)
  const values: Record<string, string> = {
    page: state.page > 1 ? String(state.page) : '',
    size: state.pageSize !== 10 ? String(state.pageSize) : '',
    q: state.email.trim(),
    plan: state.planId,
    status: state.banState,
    sort: state.sortField !== 'id' ? state.sortField : '',
    desc: state.sortDesc ? '' : '0',
  }
  for (const [key, value] of Object.entries(values)) {
    if (value) next.set(key, value)
    else next.delete(key)
  }
  return next
}
