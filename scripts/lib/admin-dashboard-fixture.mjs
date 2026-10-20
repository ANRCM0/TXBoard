export function dashboardFixture(input, { nodes = [], users = [] } = {}) {
  const now = Math.floor(Date.now() / 1000), today = new Date(now * 1000).toISOString().slice(0, 10)
  const start = input.start_date ?? new Date(Date.parse(today + 'T00:00:00Z') - 29 * 86400000).toISOString().slice(0, 10)
  const end = input.end_date ?? today, first = Date.parse(start + 'T00:00:00Z') / 1000
  const final = Date.parse(end + 'T00:00:00Z') / 1000
  const trends = Array.from({ length: (final - first) / 86400 + 1 }, (_, index) => ({
    date: new Date((first + index * 86400) * 1000).toISOString().slice(0, 10),
    income: [{ currency: 'CNY', amount_minor: String(2900 + index * 317), count: String(2 + index % 9) },
      { currency: 'USD', amount_minor: String(1200 + index * 149), count: String(1 + index % 5) }],
    earned_commission: [{ currency: 'CNY', amount_minor: String(290 + index * 31), count: String(1 + index % 3) }],
    commission_transfer: [{ currency: 'CNY', amount_minor: String(110 + index * 17), count: String(1 + index % 2) }],
  }))
  return { time_zone: 'UTC', basis: 'native-paid-purchase-and-settled-usage/v1', observed_at: now,
    start_date: start, end_date: end, starts_at: first, ends_before: Math.min(final + 86400, now + 1),
    cards: { today_income: trends.at(-1).income, month_income: [{ currency: 'USD', amount_minor: '9007199254740993', count: '21' }],
      last_month_income: [{ currency: 'CNY', amount_minor: '156000', count: '53' }],
      month_commission_transfer: [{ currency: 'CNY', amount_minor: '12000', count: '8' }],
      last_month_commission_transfer: [], month_new_accounts: '21', pending_tickets: '3', pending_commission_payouts: '2' },
    online: { nodes: null, users: null, devices: null }, trends,
    rankings: { nodes: nodes.slice(0, 10).map((row, index) => ({ id: row.id, name: row.name, total_bytes: String(9007199254740999n - BigInt(index) * 100000000n) })),
      users: users.slice(0, 10).map((row, index) => ({ id: row.id, name: row.email, total_bytes: String(3250000000n - BigInt(index) * 100000000n) })) },
  }
}
