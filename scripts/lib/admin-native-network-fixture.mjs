import { readFile } from 'node:fs/promises'

// Snapshot of the native ProtocolRegistry::metadata(), used only by browser fixtures.
const protocols = JSON.parse(await readFile(new URL('./node-protocol-metadata.json', import.meta.url), 'utf8'))

export function nativeNetworkRead(operation, input, { node, machine, token = 'synthetic-preview-server-token' }) {
  if (operation === 'network.machines.overview.get') return { observed_at: Math.floor(Date.now() / 1000), freshness_seconds: 180, high_load_threshold_percent: 80, total: 1, enabled: machine.is_active ? 1 : 0, online: 0, high_load: 0, unknown_load: 1 }
  if (operation === 'network.nodes.protocols.list') return { metadata_json: JSON.stringify(protocols) }
  if (operation === 'network.nodes.configuration.get') {
    const protocol = protocols.find(row => row.type === node.type)
    return { node_id: input.node_id, revision: 'a'.repeat(64), configuration_json: JSON.stringify({
      type: node.type, name: node.name, host: node.host, port: String(node.port), server_port: Number(node.port),
      code: null, group_ids: node.group_ids ?? [], route_ids: node.route_ids ?? [], parent_id: null,
      machine_id: node.machine_id ?? null, show: node.show, enabled: node.enabled, rate: '1',
      rate_time_enable: false, rate_time_ranges: [], tags: node.tags ?? [], transfer_enable: null,
      protocol_settings: structuredClone(protocol?.defaults ?? {}), custom_outbounds: [], custom_routes: [], cert_config: null,
    }) }
  }
  if (operation === 'network.machines.credentials.get') return { token, install_command: 'echo synthetic-preview-only --token ' + token }
  if (operation === 'network.machines.history.get') {
    const end = Math.floor(Date.now() / 1000), count = Math.min(input.limit ?? 60, 36)
    return { machine_id: machine.id, range_hours: input.range_hours, points: Array.from({ length: count }, (_, index) => ({
      recorded_at: end - (count - index - 1) * Math.floor(input.range_hours * 3600 / count),
      cpu: String(12 + index * 7 % 61) + '.5', mem_total: '8589934592', mem_used: String((26n + BigInt(index % 17)) * 100000000n),
      disk_total: '107374182400', disk_used: '36700160000', net_in_speed: index % 5 === 0 ? null : String(1000000 + index * 130000),
      net_out_speed: String(2000000 + (index * 17 % 23) * 100000),
    })) }
  }
}
