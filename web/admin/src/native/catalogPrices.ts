const months = { quarterly: 3n, half_yearly: 6n, yearly: 12n, two_yearly: 24n, three_yearly: 36n }

export function monthlyPriceDraft<T extends Record<string, string>>(prices: T, replace: boolean): T {
  if (!/^[1-9][0-9]{0,18}$/.test(prices.monthly)) throw new Error('请先填写大于 0 的月付价格')
  const next = { ...prices }
  for (const [period, count] of Object.entries(months)) {
    if (prices[period] === '0' || !replace && prices[period] !== '') continue
    const amount = BigInt(prices.monthly) * count
    if (amount > 9223372036854775807n) throw new Error('生成的周期价格超出允许范围，请分别填写')
    next[period as keyof T] = String(amount) as T[keyof T]
  }
  return next
}
