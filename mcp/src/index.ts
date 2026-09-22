import { createServer, type IncomingMessage, type ServerResponse } from 'node:http';
import { createMcpHandler, McpServer } from '@modelcontextprotocol/server';
import { toNodeHandler } from '@modelcontextprotocol/node';
import * as z from 'zod/v4';

const baseUrl = (process.env.TXBOARD_BASE_URL ?? 'http://127.0.0.1').replace(/\/$/, '');
const host = process.env.MCP_HOST ?? '127.0.0.1';
const port = Number.parseInt(process.env.MCP_PORT ?? '3000', 10);
const allowedHosts = csv(process.env.MCP_ALLOWED_HOSTS ?? 'localhost,127.0.0.1,txboard-mcp');
const allowedOrigins = csv(process.env.MCP_ALLOWED_ORIGINS ?? '');

type Envelope<T> = {
  status: 'success' | 'fail';
  message?: string;
  data: T;
  error?: unknown;
};

function csv(value: string): string[] {
  return value.split(',').map((item) => item.trim().toLowerCase()).filter(Boolean);
}

function bearerFrom(req: IncomingMessage): string | null {
  const raw = req.headers.authorization;
  if (!raw || !/^Bearer\s+\S+$/i.test(raw)) return null;
  return raw;
}

function requestAllowed(req: IncomingMessage): boolean {
  const rawHost = (req.headers.host ?? '').toLowerCase();
  const hostname = rawHost.startsWith('[')
    ? rawHost.slice(1, rawHost.indexOf(']'))
    : rawHost.split(':')[0];

  if (!hostname || !allowedHosts.includes(hostname)) return false;

  const origin = (req.headers.origin ?? '').toLowerCase();
  if (!origin) return true;
  return allowedOrigins.some((allowed) => origin === allowed || origin.startsWith(allowed + ':'));
}

async function api<T>(authorization: string, path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(baseUrl + '/api/v2/agent' + path, {
    ...init,
    headers: {
      accept: 'application/json',
      'content-type': 'application/json',
      authorization,
      'x-agent-client': 'txboard-mcp',
      'x-agent-protocol': 'mcp',
      ...(init?.headers ?? {}),
    },
    signal: AbortSignal.timeout(15_000),
  });

  let payload: Envelope<T> | null = null;
  try {
    payload = await response.json() as Envelope<T>;
  } catch {
    throw new Error('TXBoard returned a non-JSON response');
  }

  if (!response.ok || payload.status !== 'success') {
    throw new Error(payload.message || 'TXBoard Agent Ops request failed');
  }
  return payload.data;
}

async function verify(authorization: string): Promise<void> {
  await api(authorization, '/whoami');
}

function result(data: unknown) {
  return {
    content: [{ type: 'text' as const, text: JSON.stringify(data, null, 2) }],
  };
}

function errorResult(error: unknown) {
  return {
    isError: true,
    content: [{
      type: 'text' as const,
      text: error instanceof Error ? error.message : String(error),
    }],
  };
}

function createTxboardServer(authorization: string): McpServer {
  const server = new McpServer(
    { name: 'txboard-agent-ops', version: '0.1.0' },
    { capabilities: { tools: {} } },
  );

  server.registerTool('txboard_system_status', {
    description: 'Read TXBoard scheduler, Horizon and WebSocket control-plane health.',
  }, async () => {
    try { return result(await api(authorization, '/system/status')); }
    catch (e) { return errorResult(e); }
  });

  server.registerTool('txboard_list_machines', {
    description: 'List TXBoard machines and their current load/heartbeat state.',
  }, async () => {
    try { return result(await api(authorization, '/machines')); }
    catch (e) { return errorResult(e); }
  });

  server.registerTool('txboard_list_nodes', {
    description: 'List TXBoard nodes with normalized availability, WebSocket state and metrics.',
  }, async () => {
    try { return result(await api(authorization, '/nodes')); }
    catch (e) { return errorResult(e); }
  });

  server.registerTool('txboard_node_metrics', {
    description: 'Read detailed metrics for one node.',
    inputSchema: z.object({ node_id: z.number().int().positive() }),
  }, async ({ node_id }) => {
    try { return result(await api(authorization, `/nodes/${node_id}/metrics`)); }
    catch (e) { return errorResult(e); }
  });

  server.registerTool('txboard_diagnose_node', {
    description: 'Return a normalized diagnosis and warnings for one node. This is read-only.',
    inputSchema: z.object({ node_id: z.number().int().positive() }),
  }, async ({ node_id }) => {
    try { return result(await api(authorization, `/nodes/${node_id}/diagnose`)); }
    catch (e) { return errorResult(e); }
  });

  server.registerTool('txboard_fleet_health', {
    description: 'Return a normalized fleet health summary with per-node severity and warnings. Respects token target scope.',
  }, async () => {
    try { return result(await api(authorization, '/fleet/health')); }
    catch (e) { return errorResult(e); }
  });

  server.registerTool('txboard_inspection_history', {
    description: 'Read recent scheduled/manual fleet inspection snapshots. Respects token target scope.',
    inputSchema: z.object({ limit: z.number().int().min(1).max(50).default(20) }),
  }, async ({ limit }) => {
    try { return result(await api(authorization, `/inspections?limit=${limit}`)); }
    catch (e) { return errorResult(e); }
  });

  server.registerTool('txboard_incident_timeline', {
    description: 'Build a node incident timeline from inspection state changes, Agent actions and Agent audit records.',
    inputSchema: z.object({
      node_id: z.number().int().positive(),
      hours: z.number().int().min(1).max(168).default(24),
      limit: z.number().int().min(1).max(100).default(100),
    }),
  }, async ({ node_id, hours, limit }) => {
    try {
      return result(await api(
        authorization,
        `/nodes/${node_id}/timeline?hours=${hours}&limit=${limit}`,
      ));
    } catch (e) { return errorResult(e); }
  });

  server.registerTool('txboard_remediation_plan', {
    description: 'Return a deterministic, safety-aware remediation plan for current node warnings. It never executes actions automatically.',
    inputSchema: z.object({ node_id: z.number().int().positive() }),
  }, async ({ node_id }) => {
    try { return result(await api(authorization, `/nodes/${node_id}/remediation`)); }
    catch (e) { return errorResult(e); }
  });

  server.registerTool('txboard_traffic_summary', {
    description: 'Read aggregate traffic, node availability and connection totals.',
  }, async () => {
    try { return result(await api(authorization, '/traffic/summary')); }
    catch (e) { return errorResult(e); }
  });

  server.registerTool('txboard_queue_status', {
    description: 'Read TXBoard Horizon queue health and recent failure counters.',
  }, async () => {
    try { return result(await api(authorization, '/queue/status')); }
    catch (e) { return errorResult(e); }
  });

  server.registerTool('txboard_audit_logs', {
    description: 'Read recent Agent Ops audit records.',
    inputSchema: z.object({ limit: z.number().int().min(1).max(100).default(50) }),
  }, async ({ limit }) => {
    try { return result(await api(authorization, `/audit?limit=${limit}`)); }
    catch (e) { return errorResult(e); }
  });

  async function requestAction(nodeId: number, action: string, input: Record<string, unknown> = {}) {
    return api(authorization, `/nodes/${nodeId}/actions`, {
      method: 'POST',
      body: JSON.stringify({ action, input }),
    });
  }

  server.registerTool('txboard_full_sync_node', {
    description: 'Request a full config/users sync. Returns pending until an administrator approves it.',
    inputSchema: z.object({ node_id: z.number().int().positive() }),
  }, async ({ node_id }) => {
    try { return result(await requestAction(node_id, 'node.full_sync')); }
    catch (e) { return errorResult(e); }
  });

  server.registerTool('txboard_reload_node_config', {
    description: 'Request validation and runtime reload of the current node config. Administrator approval is required.',
    inputSchema: z.object({ node_id: z.number().int().positive() }),
  }, async ({ node_id }) => {
    try { return result(await requestAction(node_id, 'ops.config.reload')); }
    catch (e) { return errorResult(e); }
  });

  server.registerTool('txboard_restart_kernel', {
    description: 'Request a proxy-kernel restart on a node. Administrator approval is required.',
    inputSchema: z.object({ node_id: z.number().int().positive() }),
  }, async ({ node_id }) => {
    try { return result(await requestAction(node_id, 'ops.kernel.restart')); }
    catch (e) { return errorResult(e); }
  });

  server.registerTool('txboard_tail_logs', {
    description: 'Request a bounded tail of the TX-Node application log. Only the configured application log source is allowed; administrator approval is required.',
    inputSchema: z.object({
      node_id: z.number().int().positive(),
      lines: z.number().int().min(1).max(200).default(100),
    }),
  }, async ({ node_id, lines }) => {
    try {
      return result(await requestAction(node_id, 'ops.logs.tail', {
        source: 'application',
        lines,
      }));
    } catch (e) { return errorResult(e); }
  });

  server.registerTool('txboard_network_test', {
    description: 'Request a bounded DNS or TCP port diagnostic. Targets are restricted by TXBoard policy and approval is required.',
    inputSchema: z.object({
      node_id: z.number().int().positive(),
      kind: z.enum(['dns', 'port_check']),
      target: z.string().min(1).max(253).optional(),
      port: z.number().int().min(1).max(65535).optional(),
    }),
  }, async ({ node_id, kind, target, port: targetPort }) => {
    try {
      const action = kind === 'dns' ? 'ops.network.dns' : 'ops.network.port_check';
      const input: Record<string, unknown> = {};
      if (target) input.target = target;
      if (targetPort) input.port = targetPort;
      return result(await requestAction(node_id, action, input));
    } catch (e) { return errorResult(e); }
  });

  server.registerTool('txboard_action_status', {
    description: 'Read the status/result of a previously requested Agent Ops action.',
    inputSchema: z.object({ request_id: z.string().min(8).max(64) }),
  }, async ({ request_id }) => {
    try { return result(await api(authorization, `/actions/${encodeURIComponent(request_id)}`)); }
    catch (e) { return errorResult(e); }
  });

  server.registerTool('txboard_verify_action', {
    description: 'Verify a completed Agent Ops action against current node telemetry instead of trusting command acknowledgement alone.',
    inputSchema: z.object({ request_id: z.string().min(8).max(64) }),
  }, async ({ request_id }) => {
    try {
      return result(await api(
        authorization,
        `/actions/${encodeURIComponent(request_id)}/verify`,
      ));
    } catch (e) { return errorResult(e); }
  });

  return server;
}

const handler = createMcpHandler(({ requestInfo }) => {
  const authorization = requestInfo?.headers.get('authorization') ?? '';
  return createTxboardServer(authorization);
}, { responseMode: 'json' });
const nodeHandler = toNodeHandler(handler);

const httpServer = createServer((req: IncomingMessage, res: ServerResponse) => {
  const pathname = new URL(req.url ?? '/', 'http://localhost').pathname;

  if (pathname === '/healthz') {
    res.writeHead(200, { 'content-type': 'application/json' });
    res.end(JSON.stringify({ status: 'ok' }));
    return;
  }

  if (pathname !== '/mcp') {
    res.writeHead(404, { 'content-type': 'application/json' });
    res.end(JSON.stringify({ error: 'not_found' }));
    return;
  }

  if (!requestAllowed(req)) {
    res.writeHead(403, { 'content-type': 'application/json' });
    res.end(JSON.stringify({ error: 'host_or_origin_not_allowed' }));
    return;
  }

  const authorization = bearerFrom(req);
  if (!authorization) {
    res.writeHead(401, { 'content-type': 'application/json', 'www-authenticate': 'Bearer' });
    res.end(JSON.stringify({ error: 'bearer_token_required' }));
    return;
  }

  void verify(authorization)
    .then(() => nodeHandler(req, res))
    .catch(() => {
      if (!res.headersSent) {
        res.writeHead(401, { 'content-type': 'application/json', 'www-authenticate': 'Bearer' });
        res.end(JSON.stringify({ error: 'invalid_agent_token' }));
      }
    });
});

httpServer.listen(port, host, () => {
  console.log(`TXBoard MCP Gateway listening on http://${host}:${port}/mcp`);
});

async function shutdown() {
  httpServer.close();
  await handler.close();
}

process.on('SIGINT', () => void shutdown().finally(() => process.exit(0)));
process.on('SIGTERM', () => void shutdown().finally(() => process.exit(0)));
