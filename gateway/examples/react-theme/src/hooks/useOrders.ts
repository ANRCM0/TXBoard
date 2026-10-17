// useOrders hook: list/status/create with gated write semantics (GW-505).
import { useCallback, useEffect, useState } from 'react'
import type { GatewayApiError, Order } from '@txboard/theme-sdk'
import { api } from '../api'

export function useOrders() {
  const [orders, setOrders] = useState<Order[]>([])
  const [error, setError] = useState<Error | null>(null)
  const [loading, setLoading] = useState(true)
  const [notice, setNotice] = useState('')
  const [current, setCurrent] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState<number | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const bootstrap = await api.bootstrap()
      if (bootstrap.capabilities.includes('orders.page')) {
        const page = await api.orders.page({ current, page_size: 20 })
        setOrders(page.items)
        setLastPage(page.lastPage)
        setTotal(page.total)
      } else {
        setOrders(await api.orders.list())
        setLastPage(1)
        setTotal(null)
      }
    } catch (err) {
      setError(err as Error)
    } finally {
      setLoading(false)
    }
  }, [current])

  const refreshStatus = useCallback(async (tradeNo: string) => {
    try {
      const status = await api.orders.status(tradeNo)
      setOrders(prev => prev.map(o => (o.trade_no === tradeNo ? { ...o, status: status.status } : o)))
    } catch (err) {
      setError(err as Error)
    }
  }, [])

  /**
   * GW-304 write: only callable when bootstrap advertised orders.create.
   * The SDK never auto-retries a write. IDEMPOTENCY_IN_PROGRESS = back off and
   * resend the SAME key; IDEMPOTENCY_CONFLICT = draft reused with a different
   * request; ORDER_REJECTED = business refusal (offer admission).
   */
  const createOrder = useCallback(async (
    payload: { offer_id: string },
    draftKey: string,
  ) => {
    const idempotencyKey = draftKey
    try {
      const bootstrap = await api.bootstrap()
      if (!bootstrap.capabilities.includes('orders.create')) return null
      const result = await api.orders.create(payload, { idempotencyKey })
      setNotice(`订单已创建：${result.trade_no}`)
      await load()
      return result
    } catch (err) {
      const gerr = err as GatewayApiError
      if (gerr.code === 'IDEMPOTENCY_IN_PROGRESS') setNotice('订单正在处理中，请稍后重试。')
      else if (gerr.code === 'IDEMPOTENCY_CONFLICT') setNotice('该订单草稿已用于不同请求，请重新选择套餐。')
      else if (gerr.code === 'ORDER_REJECTED') setNotice('订单被拒绝：' + gerr.message)
      else setError(gerr)
      return null
    }
  }, [load])

  useEffect(() => { void load() }, [load])

  return { orders, error, loading, notice, current, lastPage, total, setCurrent, reload: load, refreshStatus, createOrder }
}
