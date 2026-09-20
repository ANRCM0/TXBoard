import { SettingsForm, type SettingField } from '../../components/config/SettingsForm'

const fields: SettingField[] = [
  { key: 'invite_force', label: '强制使用邀请码注册', type: 'switch', section: '邀请' },
  { key: 'invite_commission', label: '默认返佣比例（%）', type: 'number', min: 0, section: '邀请' },
  { key: 'invite_gen_limit', label: '邀请码生成数量限制', type: 'number', min: 0, section: '邀请' },
  { key: 'invite_never_expire', label: '邀请码永不过期', type: 'switch', section: '邀请' },

  { key: 'commission_first_time_enable', label: '仅首次购买返佣', type: 'switch', section: '佣金' },
  { key: 'commission_auto_check_enable', label: '自动确认佣金', type: 'switch', section: '佣金' },
  { key: 'commission_withdraw_limit', label: '最低提现金额', type: 'number', min: 0, section: '佣金' },
  { key: 'commission_withdraw_method', label: '提现方式白名单', type: 'string-array', section: '佣金', placeholder: 'alipay\nwechat\nusdt' },
  { key: 'withdraw_close_enable', label: '关闭提现', type: 'switch', section: '佣金' },

  { key: 'commission_distribution_enable', label: '启用多级分销', type: 'switch', section: '多级分销' },
  { key: 'commission_distribution_l1', label: '一级分销比例（%）', type: 'number', min: 0, section: '多级分销', visibleWhen: v => Boolean(v.commission_distribution_enable) },
  { key: 'commission_distribution_l2', label: '二级分销比例（%）', type: 'number', min: 0, section: '多级分销', visibleWhen: v => Boolean(v.commission_distribution_enable) },
  { key: 'commission_distribution_l3', label: '三级分销比例（%）', type: 'number', min: 0, section: '多级分销', visibleWhen: v => Boolean(v.commission_distribution_enable) },
]

export function InviteSettingsPage() {
  return <SettingsForm settingKey="invite" title="邀请与佣金" description="邀请、佣金提现和多级分销配置。" fields={fields}/>
}
