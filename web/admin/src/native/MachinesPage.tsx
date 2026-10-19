import type { CoreOutput } from '@txboard/core-sdk'
import { Inventory, formatTimestamp } from './Inventory'
import type { Column } from '../components/ui/DataTable'
import { admin } from './session'
import '../pages/server/MachinesPage.css'

type Machine = CoreOutput<'network.machines.list'>[number]
const columns: Column<Machine>[] = [
  { key: 'name', header: '服务器', render: row => <div className="native-row-identity">
    <strong>{row.name}</strong><small>#{row.id}</small>
  </div> },
  { key: 'active', header: '管理状态', render: row => row.is_active ? '已启用' : '已停用' },
  { key: 'channel', header: '镜像通道', render: row => row.image_channel },
  { key: 'nodes', header: '绑定节点', render: row => row.servers_count },
  { key: 'seen', header: '最近心跳', render: row => formatTimestamp(row.last_seen_at) },
]
export function MachinesPage() {
  return <Inventory operation="network.machines.list" title="服务器管理" columns={columns}
    load={(page, signal) => admin.read('network.machines.list', { page, per_page: 20 }, signal)}
    matches={(row, search) => [row.id, row.name, row.image_channel]
      .some(value => value.toLowerCase().includes(search))} />
}
