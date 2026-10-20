import { t } from '../lib/i18n'
const periods: Record<string, string> = {
  monthly: '月付', quarterly: '季付', half_yearly: '半年付', yearly: '年付',
  two_yearly: '两年付', three_yearly: '三年付', onetime: '一次性', reset_traffic: '流量重置包',
}

export const orderPeriod = (period: string) => t(periods[period] ?? period)
export const orderType = (type: number) => t(['未分类', '新购', '续费', '升级', '流量重置'][type] ?? '未知类型')
