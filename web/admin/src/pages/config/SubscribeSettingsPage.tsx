import { SettingsForm, type SettingField } from '../../components/config/SettingsForm'

const fields: SettingField[] = [
  { key: 'plan_change_enable', label: '允许变更套餐', type: 'switch', section: '套餐与订单' },
  { key: 'surplus_enable', label: '启用剩余价值折算', type: 'switch', section: '套餐与订单' },
  { key: 'new_order_event_id', label: '新购订单事件 ID', type: 'number', section: '套餐与订单' },
  { key: 'renew_order_event_id', label: '续费订单事件 ID', type: 'number', section: '套餐与订单' },
  { key: 'change_order_event_id', label: '变更套餐事件 ID', type: 'number', section: '套餐与订单' },

  { key: 'reset_traffic_method', label: '系统流量重置方式', type: 'select', section: '流量', options: [
    { label: '每月 1 号', value: 0 },
    { label: '按月循环', value: 1 },
    { label: '不重置', value: 2 },
    { label: '每年 1 月 1 日', value: 3 },
    { label: '按年循环', value: 4 },
  ] },
  { key: 'default_remind_expire', label: '默认开启到期提醒', type: 'switch', section: '流量' },
  { key: 'default_remind_traffic', label: '默认开启流量提醒', type: 'switch', section: '流量' },

  { key: 'show_info_to_server_enable', label: '向节点下发用户信息', type: 'switch', section: '节点下发' },
  { key: 'show_protocol_to_server_enable', label: '向节点下发协议信息', type: 'switch', section: '节点下发' },
  { key: 'subscribe_path', label: '订阅路径', section: '订阅入口', placeholder: 's', description: '例如 s，对应订阅 URL 中的路径段。' },
]

export function SubscribeSettingsPage() {
  return <SettingsForm settingKey="subscribe" title="订阅设置" description="包含套餐变更、流量重置、事件 ID、节点下发和订阅路径。" fields={fields}/>
}
