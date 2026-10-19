import type { CoreOutput } from '@txboard/core-sdk'
import { Inventory, dash } from './Inventory'
import type { Column } from '../components/ui/DataTable'
import { admin } from './session'
import '../pages/server/NodesPage.css'

type Node = CoreOutput<'network.nodes.list'>[number]
const columns: Column<Node>[] = [
  { key: 'name', header: '节点', render: node => <div className="nodes-v3-identity">
    <strong>{node.name}</strong><small>#{node.id} · {node.type}</small>
  </div> },
  { key: 'host', header: '连接地址', render: node =>
    <code>{node.host.includes(':') ? '[' + node.host + ']' : node.host}:{node.port}</code> },
  { key: 'machine', header: '所属服务器', render: node => dash(node.machine_id) },
  { key: 'state', header: '状态', render: node => <span>{node.enabled ? '已启用' : '已禁用'} · {node.show ? '显示' : '隐藏'}</span> },
  { key: 'traffic', header: '上传 / 下载字节', render: node => <span>{dash(node.u)} / {dash(node.d)}</span> },
]
export function NodesPage() {
  return <Inventory operation="network.nodes.list" title="节点管理" columns={columns}
    load={(page, signal) => admin.read('network.nodes.list', { page, per_page: 20 }, signal)}
    matches={(node, search) => [node.id, node.name, node.type, node.host, node.machine_id]
      .some(value => String(value ?? '').toLowerCase().includes(search))} />
}
