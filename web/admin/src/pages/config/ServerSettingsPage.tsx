import { SettingsForm, type SettingField } from '../../components/config/SettingsForm'

const fields: SettingField[] = [
  { key: 'server_token', label: '服务器通讯密钥', type: 'password', section: '通讯', description: '后端要求至少 16 位。' },
  { key: 'server_pull_interval', label: '拉取间隔（秒）', type: 'number', min: 1, section: '通讯' },
  { key: 'server_push_interval', label: '上报间隔（秒）', type: 'number', min: 1, section: '通讯' },
  { key: 'device_limit_mode', label: '设备限制模式', type: 'number', min: 0, section: '设备限制' },
]

export function ServerSettingsPage() {
  return <SettingsForm settingKey="server" title="服务器配置" description="原生节点通讯 Token、拉取/上报间隔与设备限制。WebSocket 开关由部署环境 TXBOARD_NATIVE_NODE_WS_ENABLED 和反向代理管理。" fields={fields}/>
}
