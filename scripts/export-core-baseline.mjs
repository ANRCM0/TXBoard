#!/usr/bin/env node
/**
 * B0: reproducible static cross-repository inventory. No PHP boot, database,
 * credentials, network, third-party dependencies or inferred runtime routes.
 */
import { readFile, readdir, mkdir, writeFile } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoNames = ['TXBoard', 'TXBoard-Deploy', 'TXBoard-Gateway', 'TX-Node'].sort();
const defaultWorkspace = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const options = { workspace: defaultWorkspace, outputDir: null, check: false };
for (let i = 2; i < process.argv.length; i++) {
  const arg = process.argv[i];
  if (arg === '--workspace') {
    if (!process.argv[i + 1] || process.argv[i + 1].startsWith('--')) throw new Error('--workspace requires a directory');
    options.workspace = path.resolve(process.argv[++i]);
  } else if (arg === '--output-dir') {
    if (!process.argv[i + 1] || process.argv[i + 1].startsWith('--')) throw new Error('--output-dir requires a directory');
    options.outputDir = path.resolve(process.argv[++i]);
  } else if (arg === '--check') options.check = true;
  else if (arg === '--help' || arg === '-h') {
    console.log('node TXBoard/scripts/export-core-baseline.mjs [--workspace <four-repo-parent>] [--output-dir <snapshot-dir>] [--check]');
    process.exit(0);
  } else throw new Error(`Unknown option: ${arg}`);
}

const slash = p => p.replaceAll('\\', '/');
const sort = rows => rows.sort((a, b) => JSON.stringify(a).localeCompare(JSON.stringify(b), 'en'));
const unique = rows => [...new Set(rows)].sort();
const sha = text => createHash('sha256').update(text).digest('hex');
const sourceCache = new Map();
async function source(file) {
  if (!sourceCache.has(file)) {
    const value = (await readFile(path.join(options.workspace, file), 'utf8')).replaceAll('\r\n', '\n');
    sourceCache.set(file, value);
  }
  return sourceCache.get(file);
}
async function filesUnder(relative, suffix) {
  const found = [];
  async function visit(dir) {
    for (const item of (await readdir(path.join(options.workspace, dir), { withFileTypes: true })).sort((a, b) => a.name.localeCompare(b.name, 'en'))) {
      const file = `${dir}/${item.name}`;
      if (item.isDirectory() && !['vendor', 'node_modules', '.git', 'dist'].includes(item.name)) await visit(file);
      else if (item.isFile() && suffix.test(item.name)) found.push(file);
    }
  }
  await visit(slash(relative));
  return found.sort();
}
const lineAt = (text, offset) => text.slice(0, offset).split('\n').length;

// Minimal lexer for the concrete PHP declarations in this baseline. Strings
// remain separate tokens; comments/heredocs cannot introduce fake references.
function lex(text, language = 'php') {
  const tokens = [];
  let i = 0;
  while (i < text.length) {
    const start = i;
    if (/\s/.test(text[i])) { i++; continue; }
    if (text.startsWith('//', i) || (language === 'php' && text[i] === '#')) {
      const end = text.indexOf('\n', i); i = end < 0 ? text.length : end; continue;
    }
    if (text.startsWith('/*', i)) {
      const end = text.indexOf('*/', i + 2); i = end < 0 ? text.length : end + 2; continue;
    }
    if (language === 'php' && text.startsWith('<<<', i)) {
      const header = /^<<<\s*['"]?([A-Za-z_][A-Za-z0-9_]*)['"]?[^\n]*\n/.exec(text.slice(i));
      if (header) {
        const close = new RegExp(`^\\s*${header[1]}(?=;|,|\\s|$)`, 'm').exec(text.slice(i + header[0].length));
        i = close ? i + header[0].length + close.index + close[0].length : text.length;
        tokens.push({ kind: 'opaque', value: 'heredoc', start, end: i });
        continue;
      }
    }
    if (text[i] === "'" || text[i] === '"' || (language !== 'php' && text[i] === '`')) {
      const quote = text[i++];
      let value = '';
      while (i < text.length && text[i] !== quote) {
        if (text[i] === '\\' && i + 1 < text.length) {
          const next = text[i + 1];
          value += next === quote || next === '\\' ? next : `\\${next}`;
          i += 2;
        } else value += text[i++];
      }
      if (i < text.length) i++;
      tokens.push({ kind: 'string', value, start, end: i });
      continue;
    }
    const name = /^[\\A-Za-z_][\\A-Za-z0-9_]*/.exec(text.slice(i));
    if (name) {
      i += name[0].length;
      tokens.push({ kind: 'name', value: name[0], start, end: i });
      continue;
    }
    const op = ['::', '->', '=>', '?->'].find(value => text.startsWith(value, i));
    if (op) { i += op.length; tokens.push({ kind: 'symbol', value: op, start, end: i }); continue; }
    i++;
    tokens.push({ kind: 'symbol', value: text[start], start, end: i });
  }
  return tokens;
}
function closeAt(tokens, index) {
  const pairs = { '(': ')', '[': ']', '{': '}' };
  const closing = pairs[tokens[index]?.value];
  if (!closing) return index;
  const stack = [closing];
  for (let i = index + 1; i < tokens.length; i++) {
    if (tokens[i].kind === 'string' || tokens[i].kind === 'opaque') continue;
    if (pairs[tokens[i].value]) stack.push(pairs[tokens[i].value]);
    else if (tokens[i].value === stack.at(-1)) {
      stack.pop();
      if (!stack.length) return i;
    }
  }
  throw new Error(`Unclosed declaration near token ${index}`);
}
function argumentsAt(tokens, open) {
  const close = closeAt(tokens, open);
  const args = [];
  let start = open + 1;
  for (let i = start; i < close; i++) {
    if (tokens[i].kind !== 'string' && ['(', '[', '{'].includes(tokens[i].value)) i = closeAt(tokens, i);
    else if (tokens[i].value === ',') { args.push(tokens.slice(start, i)); start = i + 1; }
  }
  if (start < close) args.push(tokens.slice(start, close));
  return { args, close };
}
const strings = tokens => tokens.filter(t => t.kind === 'string').map(t => t.value);
const literal = tokens => tokens?.length === 1 && tokens[0].kind === 'string' ? tokens[0].value : null;
const expression = tokens => tokens.map(t => t.kind === 'string' ? JSON.stringify(t.value) : t.value).join(' ');
function phpContext(tokens) {
  const imports = new Map();
  let namespace = '';
  let className = '';
  const importRanges = [];
  for (let i = 0; i < tokens.length; i++) {
    if (tokens[i].value === 'namespace' && tokens[i + 1]?.kind === 'name') namespace = tokens[i + 1].value;
    if (['class', 'interface', 'trait', 'enum'].includes(tokens[i].value) && tokens[i - 1]?.value !== '::' && tokens[i + 1]?.kind === 'name' && !className) className = tokens[i + 1].value;
    if (tokens[i].value === 'use' && tokens[i + 1]?.kind === 'name' && tokens[i + 2]?.value !== '(') {
      let end = i + 1;
      while (end < tokens.length && tokens[end].value !== ';' && tokens[end].value !== '{') end++;
      if (tokens[end]?.value === ';') {
        const imported = tokens[i + 1].value.replace(/^\\/, '');
        const alias = tokens[i + 2]?.value === 'as' ? tokens[i + 3].value : imported.split('\\').at(-1);
        if (imported.includes('\\')) { imports.set(alias, imported); importRanges.push([i, end]); }
      }
    }
  }
  const resolve = name => {
    if (['self', 'static'].includes(name)) return className ? `${namespace}\\${className}` : null;
    if (name.startsWith('\\')) return name.slice(1);
    const [head, ...tail] = name.split('\\');
    if (imports.has(head)) return [imports.get(head), ...tail].join('\\');
    if (name.startsWith('App\\')) return name;
    return namespace ? `${namespace}\\${name}` : name;
  };
  return { namespace, className, imports, importRanges, resolve };
}
async function phpFile(file) {
  const text = await source(file);
  const tokens = lex(text);
  return { text, tokens, context: phpContext(tokens) };
}
function appReferences(tokens, context, text, knownClasses) {
  const refs = [];
  for (let i = 0; i < tokens.length; i++) {
    const token = tokens[i];
    if (token.kind !== 'name') continue;
    if (tokens[i - 1]?.value === 'namespace' || (['class', 'interface', 'trait', 'enum'].includes(tokens[i - 1]?.value) && tokens[i - 2]?.value !== '::')) continue;
    const inImport = context.importRanges.some(([start, end]) => i > start && i < end);
    if (inImport && tokens[i - 1]?.value !== 'use') continue;
    // Method/function names, properties and named array keys are not classes.
    if (['->', '?->', '$', 'function', 'const', 'as'].includes(tokens[i - 1]?.value)) continue;
    const target = inImport ? token.value.replace(/^\\/, '') : context.resolve(token.value);
    const qualified = token.value.startsWith('App\\') || token.value.startsWith('\\App\\');
    const importAlias = context.imports.has(token.value.split('\\')[0]);
    if (!target?.startsWith('App\\') || !(inImport || qualified || importAlias || knownClasses.has(target))) continue;
    // Bare words matching a same-namespace class are accepted only in class
    // syntax (new/static/type/instanceof), avoiding constant/function guesses.
    if (!inImport && !qualified && !importAlias && !['::', '$', '|', '?', '&', ')', ','].includes(tokens[i + 1]?.value) && !['new', 'instanceof', 'extends', 'implements', ':', '|', '?'].includes(tokens[i - 1]?.value)) continue;
    refs.push({ target, kind: inImport ? 'import' : 'symbol', line: lineAt(text, token.start) });
  }
  return sort([...new Map(refs.map(r => [JSON.stringify(r), r])).values()]);
}
function tableReferences(tokens, text) {
  const calls = [];
  for (let i = 0; i < tokens.length; i++) {
    if (!['DB', '\\Illuminate\\Support\\Facades\\DB', 'Illuminate\\Support\\Facades\\DB'].includes(tokens[i].value) || tokens[i + 1]?.value !== '::' || tokens[i + 3]?.value !== '(') continue;
    const method = tokens[i + 2].value;
    if (!['table', 'select', 'selectOne', 'insert', 'update', 'delete', 'statement', 'unprepared', 'raw'].includes(method)) continue;
    const { args } = argumentsAt(tokens, i + 3);
    if (method === 'table') {
      const value = literal(args[0]);
      calls.push({ method, table: value, resolution: value === null ? 'dynamic-unresolved' : 'literal', line: lineAt(text, tokens[i].start) });
    } else {
      const names = unique(strings(args[0] ?? []).flatMap(value => value.match(/\btx_[a-z0-9_]+\b/g) ?? []));
      if (names.length) calls.push({ method, tables: names, resolution: 'literal-sql-token-candidate', line: lineAt(text, tokens[i].start) });
    }
  }
  const literals = tokens.filter(t => t.kind === 'string' && /^tx_[a-z0-9_]+(?:[.,:].*)?$/.test(t.value)).map(t => ({ table: t.value.split(/[.,:]/)[0], line: lineAt(text, t.start), kind: 'literal-table-token-candidate' }));
  return { dbCalls: calls, literalCandidates: sort(literals) };
}

const appFiles = await filesUnder('TXBoard/api/app', /\.php$/);
const routeFiles = await filesUnder('TXBoard/api/routes', /\.php$/);
const migrationFiles = await filesUnder('TXBoard/api/database/migrations', /\.php$/);
const gatewayFiles = [...await filesUnder('TXBoard-Gateway/apps/gateway/src', /\.ts$/), ...await filesUnder('TXBoard-Gateway/packages/theme-sdk/src', /\.ts$/)].sort();
const nodeFiles = (await filesUnder('TX-Node/internal', /\.go$/)).filter(file => !file.endsWith('_test.go'));
const deployFiles = await filesUnder('TXBoard-Deploy', /\.(sh|yaml|yml)$/);
const mcpFiles = await filesUnder('TXBoard/mcp/src', /\.ts$/);
const parsed = new Map(await Promise.all(appFiles.map(async file => [file, await phpFile(file)])));
const knownClasses = new Set([...parsed.values()].map(({ context }) => `${context.namespace}\\${context.className}`));
const models = [];
for (const file of appFiles.filter(f => f.includes('/Models/'))) {
  const { text, tokens, context } = parsed.get(file);
  const tables = [];
  const relations = [];
  const methods = [];
  for (let i = 0; i < tokens.length; i++) {
    if (tokens[i].value === '$' && tokens[i + 1]?.value === 'table' && tokens[i + 2]?.value === '=' && tokens[i + 3]?.kind === 'string') tables.push({ name: tokens[i + 3].value, line: lineAt(text, tokens[i].start) });
    if (tokens[i].value === 'function' && tokens[i + 1]?.kind === 'name') methods.push({ name: tokens[i + 1].value, start: i });
    if (tokens[i].value === '->' && ['belongsTo', 'hasOne', 'hasMany', 'belongsToMany', 'hasManyThrough', 'hasOneThrough', 'morphTo', 'morphMany', 'morphOne', 'morphToMany', 'morphedByMany'].includes(tokens[i + 1]?.value) && tokens[i + 2]?.value === '(') {
      const { args } = argumentsAt(tokens, i + 2);
      const target = args[0]?.find(t => t.kind === 'name');
      const targetClass = args[0]?.some(t => t.value === '::') && target ? context.resolve(target.value) : null;
      relations.push({ method: methods.at(-1)?.name ?? null, relation: tokens[i + 1].value, targetClass, keys: args.slice(1).map(literal), line: lineAt(text, tokens[i].start) });
    }
  }
  models.push({ file, class: `${context.namespace}\\${context.className}`, explicitTables: tables, relationships: relations, references: appReferences(tokens, context, text, knownClasses) });
}
const migrations = [];
for (const file of migrationFiles) {
  const { text, tokens } = await phpFile(file);
  const operations = [];
  let method = null;
  const literalVariables = new Map();
  for (let i = 0; i < tokens.length; i++) {
    if (tokens[i].value === 'function' && tokens[i + 1]?.kind === 'name') { method = tokens[i + 1].value; literalVariables.clear(); }
    if (tokens[i].value === '$' && tokens[i + 2]?.value === '=' && tokens[i + 3]?.kind === 'string') literalVariables.set(tokens[i + 1].value, tokens[i + 3].value);
    if (tokens[i].value === 'Schema' && tokens[i + 1]?.value === '::' && tokens[i + 3]?.value === '(') {
      const { args } = argumentsAt(tokens, i + 3);
      const value = literal(args[0]);
      const variable = args[0]?.length === 2 && args[0][0].value === '$' ? literalVariables.get(args[0][1].value) : null;
      operations.push({ phase: method, operation: tokens[i + 2].value, table: value ?? variable ?? null, resolution: value !== null ? 'literal' : variable ? 'local-literal-assignment-candidate' : 'dynamic-unresolved', targetTable: tokens[i + 2].value === 'rename' ? literal(args[1]) : null, line: lineAt(text, tokens[i].start) });
    }
  }
  migrations.push({ file, schemaOperations: operations, tableReferences: tableReferences(tokens, text) });
}
const dependencies = [];
for (const file of appFiles.filter(f => /\/(Services|Domains|Jobs)\//.test(f))) {
  const { text, tokens, context } = parsed.get(file);
  const references = appReferences(tokens, context, text, knownClasses);
  const tables = tableReferences(tokens, text);
  const infrastructureCalls = [];
  const hooks = [];
  for (let i = 0; i < tokens.length; i++) {
    if (tokens[i + 1]?.value === '::' && tokens[i + 3]?.value === '(') {
      const symbol = context.resolve(tokens[i].value);
      if (['Illuminate\\Support\\Facades\\DB', 'Illuminate\\Support\\Facades\\Redis', 'Illuminate\\Support\\Facades\\Cache', 'Illuminate\\Support\\Facades\\Queue', 'Illuminate\\Support\\Facades\\Bus'].includes(symbol)) infrastructureCalls.push({ symbol, method: tokens[i + 2].value, line: lineAt(text, tokens[i].start) });
      if (symbol === 'App\\Services\\Plugin\\HookManager') {
        const { args } = argumentsAt(tokens, i + 3);
        hooks.push({ method: tokens[i + 2].value, hook: literal(args[0]), line: lineAt(text, tokens[i].start) });
      }
    }
  }
  dependencies.push({ file, class: `${context.namespace}\\${context.className}`, references, ...tables, infrastructureCalls, hooks });
}

function fluentChain(tokens, start) {
  const calls = [];
  let i = start + 1;
  while (['::', '->'].includes(tokens[i]?.value) && tokens[i + 2]?.value === '(') {
    const { args, close } = argumentsAt(tokens, i + 2);
    calls.push({ method: tokens[i + 1].value, args, open: i + 2, close });
    i = close + 1;
  }
  return calls;
}
const middlewareValues = args => (args ?? []).flatMap(arg => strings(arg).concat(arg.filter((t, i) => t.kind === 'name' && arg[i + 1]?.value === '::').map(t => `${t.value.replace(/^\\/, '')}::class`)));
function classifyRoute(route) {
  if (route.path === '/api/health' || route.path === '/txapi/health') return 'health';
  if (route.middleware.some(m => m === 'admin' || m === 'admin.path') && route.path.startsWith('/txapi/')) return 'admin';
  if (route.middleware.some(m => m.includes('TxNodeAuth'))) return 'node-machine';
  if (route.middleware.includes('agent')) return 'agent';
  if (route.path.includes('pairings/redeem')) return 'agent-pairing';
  if (route.middleware.includes('txapi.user')) return 'user';
  if (route.middleware.includes('client')) return 'subscription-url';
  if (route.path.includes('payment/webhook')) return 'payment-callback';
  if (route.path.includes('telegram/webhook')) return 'telegram-callback';
  if (route.file.endsWith('web.php')) return route.middleware.includes('admin.path') ? 'admin-spa' : 'web';
  return 'public-or-auth-controller-policy';
}
async function routesIn(file, basePrefix, baseMiddleware) {
  const { text, tokens, context } = await phpFile(file);
  const groups = [];
  const declarations = [];
  for (let i = 0; i < tokens.length; i++) {
    if (tokens[i].value !== 'Route' || tokens[i + 1]?.value !== '::') continue;
    const chain = fluentChain(tokens, i);
    const group = chain.find(call => call.method === 'group');
    if (group) {
      const body = group.args[0]?.find(t => t.value === '{');
      if (body) {
        const begin = tokens.indexOf(body);
        groups.push({ start: begin, end: closeAt(tokens, begin), prefix: literal(chain.find(c => c.method === 'prefix')?.args[0]), middleware: middlewareValues(chain.find(c => c.method === 'middleware')?.args) });
      }
    }
    const route = chain[0];
    if (!route || !['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'match', 'any'].includes(route.method)) continue;
    const pathArg = route.args[route.method === 'match' ? 1 : 0];
    const action = route.args[route.method === 'match' ? 2 : 1] ?? [];
    const parents = groups.filter(g => g.start < i && i < g.end);
    const declaredPath = literal(pathArg);
    const resolvedPath = '/' + [basePrefix, ...parents.map(g => g.prefix), declaredPath ?? '<dynamic>'].filter(Boolean).map(s => s.replace(/^\/+|\/+$/g, '')).join('/');
    const controllerIndex = action.findIndex((t, index) => t.kind === 'name' && action[index + 1]?.value === '::' && action[index + 2]?.value === 'class');
    const record = {
      file, line: lineAt(text, tokens[i].start),
      methods: route.method === 'match' ? strings(route.args[0]).map(s => s.toUpperCase()) : route.method === 'any' ? ['ANY'] : [route.method.toUpperCase()],
      path: resolvedPath, declaredPath, pathResolution: declaredPath === null ? 'dynamic-unresolved' : 'static-declaration-candidate',
      dynamicPathExpression: declaredPath === null ? expression(pathArg) : null,
      middleware: unique([...baseMiddleware, ...parents.flatMap(g => g.middleware), ...chain.filter(c => c.method === 'middleware').flatMap(c => middlewareValues(c.args))]),
      controller: controllerIndex >= 0 ? context.resolve(action[controllerIndex].value) : null,
      action: controllerIndex >= 0 ? strings(action).at(-1) ?? null : 'closure-or-variable',
    };
    record.classification = classifyRoute(record);
    declarations.push(record);
  }
  return declarations;
}
const routeDeclarations = sort([
  ...await routesIn('TXBoard/api/app/Providers/RouteServiceProvider.php', '', []),
  ...await routesIn('TXBoard/api/routes/txapi.php', 'txapi', ['api', 'txapi.request-id']),
  ...await routesIn('TXBoard/api/routes/web.php', '', ['web']),
]);
const registrationEntrypoints = [
  { file: 'TXBoard/api/app/Providers/RouteServiceProvider.php', role: 'Laravel map; /api/health and /txapi/health; /txapi -> routes/txapi.php; web -> routes/web.php' },
  { file: 'TXBoard/api/routes/txapi.php', role: 'Only registered TXBoard application HTTP API declarations; group/auth candidates in routeDeclarations' },
  { file: 'TXBoard/api/routes/web.php', role: 'Web rendering, private subscription URL and dynamic Admin SPA' },
  { file: 'TXBoard/api/app/Http/Kernel.php', role: 'Middleware aliases; actual authorization also requires controllers/services/config' },
  { file: 'TXBoard/api/app/WebSocket/NativeNodeWebSocket.php', role: 'Native Workerman WebSocket protocol; not a Laravel route:list entry' },
  { file: 'TXBoard/api/app/Console/Commands/NodeWebSocketServer.php', role: 'Workerman listener bootstrap and feature/configuration boundary' },
  { file: 'TXBoard/api/app/Services/Plugin/PluginManager.php', role: 'Trusted in-process plugin dynamic registration; not resolved by this static inventory' },
];

// Deliberate mapping by business operation, checked against declarations. It is
// a migration candidate, never evidence of equivalent behavior/authorization.
const candidateRoutes = {
  guestConfig: ['GET', '/txapi/public/site-config'], guestPlans: ['GET', '/txapi/plans'], guestPaymentMethods: null,
  login: ['POST', '/txapi/auth/login'], userProfile: ['GET', '/txapi/me'], userOrders: ['GET', '/txapi/orders'],
  userOrderDetail: ['GET', '/txapi/orders/{tradeNo}/detail'], userSubscription: ['GET', '/txapi/me/subscription'],
  userPaymentMethods: ['GET', '/txapi/billing/payment-methods'], userNotices: ['GET', '/txapi/notices'],
  userStats: ['GET', '/txapi/me/dashboard-stats'], userOrderStatus: ['GET', '/txapi/orders/{tradeNo}'],
  register: ['POST', '/txapi/auth/register'], sendEmailCode: ['POST', '/txapi/auth/email-code'], userOrderCreate: ['POST', '/txapi/orders'],
};
const upstreamFile = 'TXBoard-Gateway/apps/gateway/src/services/upstream.ts';
const upstreamText = await source(upstreamFile);
const upstreamTokens = lex(upstreamText, 'ts');
const allowStart = upstreamTokens.findIndex(t => t.value === 'allowlistedPaths');
const allowOpen = upstreamTokens.findIndex((t, i) => i > allowStart && t.value === '{');
const allowEnd = closeAt(upstreamTokens, allowOpen);
const gatewayOperations = [];
for (let i = allowOpen + 1; i < allowEnd; i++) {
  if (upstreamTokens[i].kind !== 'name' || upstreamTokens[i + 1]?.value !== ':' || upstreamTokens[i + 2]?.kind !== 'string') continue;
  const operation = upstreamTokens[i].value;
  const candidate = candidateRoutes[operation] ?? null;
  const matched = candidate ? routeDeclarations.filter(r => r.path === candidate[1] && r.methods.includes(candidate[0])) : [];
  gatewayOperations.push({ operation, method: ['login', 'register', 'sendEmailCode', 'userOrderCreate'].includes(operation) ? 'POST' : 'GET', path: upstreamTokens[i + 2].value, file: upstreamFile, line: lineAt(upstreamText, upstreamTokens[i].start), coreCandidate: candidate ? { method: candidate[0], path: candidate[1], declarations: matched.map(({ file, line, middleware }) => ({ file, line, middleware })) } : null, compatibility: 'incompatible-unregistered-legacy-path', equivalence: 'not-proven' });
}

const nodeEventSources = nodeFiles.filter(file => /\/internal\/(controlplane|txboard)\//.test(file) || file.endsWith('/panel/ws.go') || file.endsWith('/nodeops/executor.go'));
const nodeEvents = [];
for (const file of nodeEventSources) {
  const text = await source(file);
  const tokens = lex(text, 'go');
  for (let i = 0; i < tokens.length; i++) {
    if (tokens[i].kind !== 'string' || !/^(sync\.|ops\.|session\.|heartbeat\.|traffic\.|node\.|device\.|auth\.)[a-z_.]+$/.test(tokens[i].value)) continue;
    nodeEvents.push({ event: tokens[i].value, file, line: lineAt(text, tokens[i].start), declaration: tokens[i - 1]?.value === '=' ? tokens.slice(Math.max(0, i - 3), i - 1).filter(t => t.kind === 'name').map(t => t.value).join(' ') : null });
  }
}
const nativeClient = 'TX-Node/internal/txboard/client.go';
const nativeText = await source(nativeClient);
const nativeTokens = lex(nativeText, 'go');
const nativeHttpOperations = [];
for (let i = 0; i < nativeTokens.length; i++) {
  if (nativeTokens[i].value !== 'request' || nativeTokens[i + 1]?.value !== '(') continue;
  const { args } = argumentsAt(nativeTokens, i + 1);
  if (literal(args[0]) && literal(args[1])) nativeHttpOperations.push({ method: literal(args[0]), path: `/txapi/node/v1${literal(args[1])}`, file: nativeClient, line: lineAt(nativeText, nativeTokens[i].start) });
}
const deployCommandFile = 'TXBoard-Deploy/txboard.sh';
const deployText = await source(deployCommandFile);
const deployDispatch = deployText.indexOf('case "$CMD" in');
const deployCommands = [...deployText.slice(deployDispatch).matchAll(/^\s{2}([a-z][a-z0-9|-]*)\)\s*([a-z_]+)/gm)].map(match => ({ aliases: match[1].split('|').sort(), handler: match[2], file: deployCommandFile, line: lineAt(deployText, deployDispatch + match.index) }));
const mcpTools = [];
for (const file of mcpFiles) {
  const text = await source(file);
  const tokens = lex(text, 'ts');
  for (let i = 0; i < tokens.length; i++) {
    if (tokens[i].value === 'registerTool' && tokens[i + 1]?.value === '(' && tokens[i + 2]?.kind === 'string') mcpTools.push({ name: tokens[i + 2].value, file, line: lineAt(text, tokens[i].start) });
  }
}
const coreNodeEvents = [];
for (const file of appFiles.filter(file => file.includes('/WebSocket/'))) {
  const { text, tokens } = parsed.get(file);
  for (const token of tokens) {
    if (token.kind === 'string' && /^(sync\.|ops\.|session\.|heartbeat\.|traffic\.|node\.|device\.|auth\.)[a-z_.]+$/.test(token.value)) coreNodeEvents.push({ event: token.value, file, line: lineAt(text, token.start) });
  }
}

const boundaryReference = target => /App\\(?:Services\\(?:AgentOps|Plugin|Module|Node[A-Za-z]*|MachineRuntimeUpdateService|ServerService)|WebSocket\\|Protocols\\|Jobs\\NodeUserSyncJob|Domains\\Network\\NativeNodeReport)/.test(target);
const couplingPoints = appFiles.flatMap(file => {
  const { text, tokens, context } = parsed.get(file);
  const references = appReferences(tokens, context, text, knownClasses).filter(r => boundaryReference(r.target));
  const hooks = dependencies.find(row => row.file === file)?.hooks ?? [];
  return references.length || hooks.length ? [{ file, references, hooks }] : [];
});
const outputRoot = options.outputDir ?? path.join(options.workspace, 'TXBoard/docs/architecture/v2');
let frozenRepositories = null;
if (options.check) {
  const saved = JSON.parse(await readFile(path.join(outputRoot, 'baseline-inventory.json'), 'utf8'));
  if (saved.schemaVersion !== 1 || !Array.isArray(saved.repositories)
    || saved.repositories.length !== repoNames.length
    || new Set(saved.repositories.map(repo => repo.name)).size !== repoNames.length) {
    throw new Error('Invalid baseline repository provenance');
  }
  frozenRepositories = saved.repositories;
}
const repositories = repoNames.map(name => {
  const repoPath = path.join(options.workspace, name);
  let head;
  if (frozenRepositories) {
    const baseline = frozenRepositories.find(repo => repo.name === name);
    if (!baseline || baseline.path !== name || !/^[a-f0-9]{40}$/.test(baseline.head)) {
      throw new Error(`Invalid baseline commit for ${name}`);
    }
    try {
      execFileSync('git', ['-C', repoPath, 'merge-base', '--is-ancestor', baseline.head, 'HEAD'], { stdio: 'pipe' });
    } catch {
      throw new Error(`Baseline commit is unavailable or not an ancestor of ${name} HEAD`);
    }
    // Snapshot provenance stays fixed across documentation-only commits.
    // The current source manifest and parsed inventory still detect code drift.
    head = baseline.head;
  } else {
    head = execFileSync('git', ['-C', repoPath, 'rev-parse', 'HEAD'], { encoding: 'utf8' }).trim();
  }
  return { name, path: name, head, worktreeStatus: 'not-frozen; CLI prints current porcelain status without persisting it' };
});
const manifestFiles = unique([...appFiles, ...routeFiles, ...migrationFiles, ...gatewayFiles, ...nodeFiles, ...deployFiles, ...mcpFiles]);
const sourceManifest = await Promise.all(manifestFiles.map(async file => ({ file, sha256: sha(await source(file)) })));
const counts = { repositories: repositories.length, sourceFiles: sourceManifest.length, modelFiles: models.length, migrationFiles: migrations.length, dependencyFiles: dependencies.length, routeDeclarations: routeDeclarations.length, gatewayOperations: gatewayOperations.length, nodeEventOccurrences: nodeEvents.length, deployCommands: deployCommands.length };
const inventory = {
  schemaVersion: 1,
  method: 'static-lexical-inventory',
  limitations: [
    'This is not a complete dynamic call graph, dependency proof, PHP AST, Laravel route:list or runtime authorization audit.',
    'All source paths are relative to the four-repository parent. Source hashes normalize CRLF to LF; no timestamp, absolute path, environment or credential value is stored.',
    'Scans all current PHP Models/Services/Domains/Jobs files; references include imports and lexical class symbols, not dynamic container, reflection, inheritance or callable resolution.',
    'Schema operation lists include up/down/guard declarations and local literal assignment candidates; they do not describe the actual database state or prove migration reversibility.',
    'Route candidates expand literal fluent group prefixes and collect middleware declarations; dynamic values, plugin registrations, reverse proxies and Workerman WSS require runtime verification.',
    'Core candidates for Gateway operations are manually defined business correspondences checked against source declarations; behavior, credentials and payload equivalence are not asserted.',
    'HEAD records identify committed baselines only. Working-tree sources may differ. Every invocation prints current dirty state; generated output never claims repositories are clean.',
    'TX-Node-Installer is absent locally and is not inventoried. Installer behavior mentioned by other repositories remains unverified.',
  ],
  repositories, counts, sourceManifest,
  core: { models, migrations, dependencies, couplingPoints, registrationEntrypoints, routeDeclarations, nodeEventOccurrences: sort(coreNodeEvents), mcp: { sourceFiles: mcpFiles, tools: mcpTools, semantics: 'MCP forwards dedicated Agent Bearer to /txapi/agent/v1; Core Agent Ops is the authorization and approval authority. No direct DB/Redis/Node execution is inferred.' } },
  gateway: { operations: sort(gatewayOperations), sourceFiles: gatewayFiles },
  node: { nativeHttpOperations, typedEventOccurrences: sort(nodeEvents), sourceFiles: nodeFiles, semantics: {
    protocol: 'Native TXAPI node/v1 uses dedicated Bearer plus X-TX-Node-ID / X-TX-Machine-ID; Xboard panel compatibility is a separate adapter.',
    billingIdentity: 'ReportPayload.Traffic map[int][2]int64 and Core settlement still use user ID; no Subscription/Access Subject billing identity is established.',
    spool: 'One immutable pending batch, stable traffic_batch_id, file+directory fsync before sending; restart replays it; corrupt/unwritable spool fails startup. HTTP ACK clears the spool.',
    ack: 'Native PostReport accepts HTTP 202, protocol_version=1, accepted=true, matching batch ID and settlement=queued. Core NativeNodeReport ACK means queue dispatch, not SQL ledger commit.',
    settlement: 'TrafficBatchJob inserts tx_traffic_batch dedupe identity and applies tx_user / tx_server / statistics in one SQL transaction. A changed duplicate payload logs an error and does not reapply.',
    recoveryLimit: 'In-memory bytes before spool flush and permanent loss after queued ACK are not resolved by Node replay; B3 requires durable Core receipt and reconciliation.',
    evidence: ['TX-Node/internal/reporting/spool.go', 'TX-Node/internal/reporting/controller.go', nativeClient, 'TX-Node/internal/controlplane/types.go', 'TXBoard/api/app/Domains/Network/NativeNodeReport.php', 'TXBoard/api/app/Jobs/TrafficBatchJob.php'],
  } },
  deploy: { commands: deployCommands, sourceFiles: deployFiles, semantics: {
    boundary: 'Image-based Core installation and server lifecycle; not TX-Node installer.',
    health: 'Core retains /api/health for container/runtime liveness, separately from /txapi/health. Do not remove it during Gateway migration.',
    update: 'Preflight checks target ownership/schema; preserves verified backup; attempts migrations and checks migration status/snapshot/runtime health.',
    recovery: 'Before any migration attempt, updater can restart the old image. After migration may have changed DB, leaves service stopped and requires compatible backup/manual recovery; no general DB rollback.',
    restore: 'Interactive automatic restore is limited to managed local MySQL; host/external database restore uses database administrator tooling.',
    checkpoint: 'Upgrade phase/attempted_migrate variables are process state, not a durable resumable cross-component upgrade journal.',
    evidence: ['TXBoard-Deploy/update.sh', 'TXBoard-Deploy/lib/backup.sh', deployCommandFile, 'TXBoard/api/app/Providers/RouteServiceProvider.php'],
  } },
};

const mdPath = p => {
  const [repo, ...parts] = p.split('/');
  const target = repo === 'TXBoard'
    ? path.posix.relative('TXBoard/docs/architecture/v2', p)
    : `https://github.com/ANRCM0/${repo}/blob/${repositories.find(r => r.name === repo).head}/${parts.join('/')}`;
  return `[${p}](${target})`;
};
const coreModelsTable = models.map(row => `| ${row.class.split('\\').at(-1)} | ${row.explicitTables.map(t => `\`${t.name}\``).join(', ') || '未声明'} | ${row.relationships.length} | ${mdPath(row.file)} |`).join('\n');
const gatewayTable = sort(gatewayOperations).map(row => `| ${row.operation} | \`${row.method} ${row.path}\` | ${row.coreCandidate ? `\`${row.coreCandidate.method} ${row.coreCandidate.path}\` (${row.coreCandidate.declarations.length ? '源码存在' : '未匹配'})` : '无公开等价声明；需独立决策'} |`).join('\n');
const categoryCounts = Object.entries(routeDeclarations.reduce((acc, r) => { acc[r.classification] = (acc[r.classification] ?? 0) + 1; return acc; }, {})).sort();
const markdown = `# B0 静态基线盘点\n\n由 \`node TXBoard/scripts/export-core-baseline.mjs --workspace <四仓父目录>\` 生成；\`--check\` 比较保存快照与当前源码及文件列表，并验证快照基准提交仍为当前 HEAD 的祖先。完整清单见 [baseline-inventory.json](baseline-inventory.json)，兼容结论见 [baseline-compatibility-matrix.md](baseline-compatibility-matrix.md)。\n\n此清单是词法静态扫描，**不是完整动态调用图、Laravel 运行时 route:list、权限验证或生产验收**。HEAD 仅标识提交基准；脚本每次在控制台输出当前工作区 dirty 状态，不冻结该易变状态，也不宣称工作区干净。源码哈希按 LF 规范化，无时间戳、绝对路径或凭据。\n\n## 基准与覆盖\n\n| 仓库 | HEAD | 工作区 |\n| --- | --- | --- |\n${repositories.map(r => `| ${r.name} | \`${r.head}\` | 每次运行以控制台 porcelain 为准 |`).join('\n')}\n\n覆盖 ${counts.sourceFiles} 个源码文件；${counts.modelFiles} 个 Models、${counts.migrationFiles} 个 migrations、${counts.dependencyFiles} 个 Services/Domains/Jobs 文件；${counts.routeDeclarations} 条 HTTP 声明候选、${counts.gatewayOperations} 个 Gateway 固定上游操作、${counts.nodeEventOccurrences} 处 Node 类型/协议事件引用、${counts.deployCommands} 组 Deploy 命令。文件增删或源码修改均进入 \`--check\`。\n\nJSON 的 \`sourceManifest\` 是完整覆盖文件列表及 SHA-256。\`core.dependencies\` 按文件列出全部直接 App import/symbol、DB 调用表名、SQL 表词候选、其他原生表字面值候选、Redis/Cache/Queue/Bus/DB 调用及 Plugin hooks。词法引用不等于执行调用，容器字符串、继承、反射、动态变量及插件发现仍需追踪。\n\n## Model 表与关系\n\n| Model | 显式表 | Eloquent 关系声明数 | 来源 |\n| --- | --- | --- | --- |\n${coreModelsTable}\n\n完整关系方法、目标类、显式键及行号在 \`core.models[].relationships\`。User 仍拥有 plan/group 与流量、订阅凭据等字段；Plan/Order 直接关联 User。未发现新版 Subscription、Access Subject 或独立资金账本模型，这仅适用于当前 Models 目录。\n\n## Migration 表操作\n\n\`core.migrations\` 覆盖全部 migration 文件及 Schema 操作，按 \`up/down\` 等方法标记，保留 create/table/rename/drop/hasTable 等操作和行号。局部变量赋值仅作为静态候选，无法解析的表保留 \`dynamic-unresolved\`。历史 migration 中旧 server 协议表或 tx_log 的 create/drop 不能被解读为实际数据库仍存在这些表。\n\n## HTTP 注册入口与分类\n\n${registrationEntrypoints.map(r => `- ${mdPath(r.file)}：${r.role}`).join('\n')}\n\n| 静态分类 | 声明数 |\n| --- | --- |\n${categoryCounts.map(([name, count]) => `| ${name} | ${count} |`).join('\n')}\n\n分类根据声明 middleware 与特定协议回调生成，匿名/auth 入口仍由控制器政策决定；不能仅凭路径证明鉴权。真正的 HTTP 注册表应在可启动环境执行 \`php artisan route:list --json\`，再按现有 \`scripts/export-route-catalog.mjs\` 校验。插件动态路由、Workerman WSS 和反向代理分别验证。\n\n## Gateway 上游对应候选\n\n| Gateway operation | 当前固定上游 | Core 静态候选 |\n| --- | --- | --- |\n${gatewayTable}\n\n**全部 ${counts.gatewayOperations} 个固定上游仍是 /api/v1 路径，当前 Core 应用 API 仅注册 /txapi，因此两份当前源码直接对接不兼容。** 以上对应关系仅表示迁移候选，不证明语义、字段、凭据或权限等价。公开支付方法无已注册匿名等价接口，不能把用户接口简单改成匿名。\n\nGateway 仅解码 legacy \`status=success\` 或 \`{data,total}\` 分页，当前 Core 的 \`{data,request_id,meta?}\` 会被拒绝；错误码也需从顶层 legacy \`code\` 迁移到 \`error.code\`。当前登录仍返回 \`auth_data: Bearer ...\`，其值来自 Sanctum；字段名相同不能证明历史值有效。登录/撤销/session DTO 需验收。\n\n## Core 耦合与 Node / Plugin / Agent\n\n\`core.couplingPoints\` 完整列出全部 Core app 文件中 App\\Protocols、Plugin、Module、AgentOps、WebSocket、Node/ServerService、NodeUserSyncJob、NativeNodeReport 的直接引用和 Services/Domains/Jobs 中的 hooks。例如 OrderService 的 order.create/open/cancel hooks 进入可信进程内 PHP 插件，UserService 的流量过滤 hook 在入队前执行。该清单不能证明事务边界、隔离或授权；后续切片应逐一验证。\n\nNative Node 使用独立 \`internal/txboard\` 适配器、专用 Bearer 身份头与 \`/txapi/node/v1\`。旧 \`internal/panel\` Xboard 适配器另存，不能凭其中 legacy 路径判断原生协议缺失。\`node.nativeHttpOperations\`、\`node.typedEventOccurrences\` 列出 HTTP 操作及全部指定协议事件源码位置，包括固定 ops 类型与业务同步。\n\nNode 发送前将一个不可变批次以稳定 ID 写入 spool 并 fsync，重启重放；成功 ACK 清本地 spool。原生 ACK 是 **202 / queued**，不是 SQL 结算确认。Core Worker 在一个 SQL 事务中写批次去重记录、用户/节点流量与统计，但入队后的持久接收、故障恢复与对账仍是 B3 缺口。流量主体当前仍是 user ID。spool 损坏/不可写会拒绝启动；未刷盘内存流量及 ACK 后永久队列丢失不能靠 Node 重放解决。详见 JSON \`node.semantics.evidence\`。\n\n## Deploy 与恢复限制\n\n\`deploy.commands\` 覆盖 txboard 顶层全部命令分支和 aliases，\`deploy.sourceFiles\` 覆盖 shell/Compose 文件。更新器有预检、备份及 migration/健康检查；未尝试迁移时可启动旧镜像，尝试迁移后要求停止服务并验证兼容备份/人工恢复，不能承诺通用数据库回滚。自动交互恢复限内置 local MySQL，host/external 数据库需管理员工具。当前 phase/attempted_migrate 是进程变量，没有持久化跨组件断点恢复日志。\n\n保留 Core \`/api/health\` 的容器存活契约；\`/txapi/health\` 是独立 TXAPI 存活入口。TX-Node-Installer 不在本地，未盘点其实现，不对其升级/恢复能力作结论。\n\n## 再生成与漂移检查\n\n\`node TXBoard/scripts/export-core-baseline.mjs --workspace <四仓父目录>\` 写入 JSON、本文及历史兼容矩阵（baseline-compatibility-matrix.md）；不覆盖当前 compatibility-matrix.md。\`node TXBoard/scripts/export-core-baseline.mjs --workspace <四仓父目录> --check\` 只比较且漂移时返回非零。工作区状态实时输出并不进入生成物，文档/脚本本身改动不会制造永久 dirty 漂移；当前代码仍按实际工作区扫描，源码变化不能被 HEAD 掩盖。检查使用快照中固定的基准 HEAD，文档提交不会改写基准或导致误报；需要建立新基线时显式重新生成。\n`;

const compatibilityMarkdown = `# B0 跨仓兼容矩阵（历史）\n\n> 历史 B0 快照（2026-10-12），仅供查证。当前接口支持范围见 [当前组件矩阵](compatibility-matrix.md)，开发期不再承担旧版本过渡兼容。\n\n由 \`scripts/export-core-baseline.mjs\` 与 [静态库存](baseline-inventory.json) 同步生成。此矩阵描述当前源码与 v2 目标间的缺口，**不代表运行时联调或发布认证**。原始路径、类、事件、来源行号及基准 HEAD 见 [基线说明](baseline-inventory.md)。\n\n| 边界 | 当前生产者 / 消费者 | 当前结论 | v2 切片要求 |\n| --- | --- | --- | --- |\n| Gateway → Core HTTP | Gateway ${gatewayOperations.length} 个固定 \`/api/v1/*\`；Core \`/txapi/*\` | **实质不兼容**：旧应用入口未注册；详见下表 | 单独原生适配器及 User 契约；保留闭合 allowlist，验证鉴权/参数/响应 |\n| 用户凭据 | Gateway SDK/DTO 使用 \`auth_data\` 并转发 Bearer；Core 登录同样返回 \`auth_data\`，TxapiUser 使用 Sanctum | Bearer 形状通过不能证明旧值可用；旧凭据/DTO 未验证迁移 | 明确 token 签发/会话/注销/撤销；验收同名字段的 Sanctum 值，不借用 Admin/Agent/Node Token |\n| JSON 与错误 | Gateway upstream 仅接受 legacy \`status=success\` 或 \`{data,total}\`，取顶层 \`code\`；Core \`{data,request_id,meta?}\` / \`error.code\` | **实质不兼容**：仅替换 URL 仍会返回 Gateway 502 或丢业务码 | 显式 Native envelope codec 与错误映射，DTO/分页/价格/订阅字段逐项验收 |\n| Gateway 订单写入 | 源码有默认关闭 \`userOrderCreate\` | 代码存在不等于启用或真实 Core 通过；上游仍 legacy | Core 持久幂等、沙箱支付/回调、负向重放测试先验收再开 flag |\n| Admin Web API | Core 动态路径 Admin Bearer/RBAC/audit；Gateway 当前用户 BFF | 当前没有统一 Admin Gateway；v1 AGENTS 边界继续有效 | v2 ADR/SC-v2 建立新边界和兼容窗口后再实施 |\n| Node HTTP/WSS | TX-Node native adapter ↔ Core node/v1，protocol_version=1，专用 Bearer/身份头，Workerman WSS | 有双方原生实现；静态对应，运行时未验收；legacy panel 是独立适配器 | 保持 v1 与 capability 协商；逐事件验证，不将 Laravel route:list 当成 WSS 可用证明 |\n| 流量/ACK | Node 稳定 batch/spool；Core 202 queued 后 Worker SQL 去重事务 | 重试基础存在；ACK 后本地 spool 清除，未形成 Core 持久接收闭环；计费 user ID | B3 持久接收/恢复/对账；B1 主体模型是前置，B2 恢复能力覆盖重大切换；需协议版本及双阶段兼容 |\n| Plugin/Theme 包 | Plugin Package v1、Theme Package v1、Admin Bridge；PHP Plugin 可信进程内 | 保持既有包/生命周期兼容；没有 PHP 沙箱隔离 | 新隔离协议另起显式版本/adapter；不暗改 v1 或让第三方宣称 runtime 状态 |\n| Module 生命周期 | Registry 库存 → ModuleLifecycle → Plugin/Theme runtime | 已有 adapter 边界；不是新版完全隔离平台 | 延续权威状态与运行时委派，不复制业务源头 |\n| Agent/MCP | MCP adapter → Agent Ops ability/scope/approval/audit → domain → typed Node ops | 已有受限调用链；ops.result 是报告，独立 verify 才能证明恢复 | 保持审批/范围/审计，禁直连 DB/Redis/Node/任意 shell；v2 不绕过 v1 控制 |\n| Deploy / health | Core \`/api/health\` + image healthcheck；Deploy 安装/更新/备份 | **必须保留 /api/health**；健康通过不证明账务/Node/插件业务正确 | 跨组件 release manifest / 兼容矩阵 / 持久 checkpoint，业务独立验收 |\n| Deploy / rollback | 无迁移尝试时旧镜像恢复；迁移后停服等待兼容备份；restore 限 local MySQL | 不保证数据库逆迁移；host/external restore 人工 | 离线副本迁移与恢复演练，版本兼容才启动，不自动降级未知 schema |\n| TX-Node-Installer | 本地缺失 | **未核查**，不从远端链接推断实现 | 单独取得仓库/版本再进入兼容矩阵，当前不阻塞本地 B0 文档 |\n\n## 已确认的字段差异\n\n| 操作 | Gateway 当前读取/校验 | Core 当前输出 |\n| --- | --- | --- |\n| 订阅概览 | txboard-v1 adapter 读取 \`u/d/transfer_enable/speed_limit\` 等 legacy 字段 | \`upload_bytes/download_bytes/traffic_limit_bytes/speed_limit_mbps\`，到期时间为 ISO8601 |\n| Dashboard | \`statsSchema\` 校验 3 个非负整数 tuple | \`unpaid_orders/open_tickets/invited_users\` 对象 |\n| 订单状态 | \`orderStatusSchema\` 校验 0..3 的单个整数 | AccountController.order 返回含 \`trade_no/status/amount_minor\` 的对象 |\n| 订单/通知分页 | legacy 上游 \`current/pageSize\` 及顶层 \`total\` | Native 参数/分页 \`meta\` 需独立适配，不能复用旧包解码 |\n\n来源：${mdPath("TXBoard-Gateway/apps/gateway/src/contracts/schemas.ts")}、${mdPath("TXBoard-Gateway/apps/gateway/src/adapters/txboard-v1.ts")}、${mdPath("TXBoard/api/app/Http/Controllers/Txapi/AccountController.php")}。这些是静态确定的差异，不是所有 DTO 的完整等价审计。\n\n## 固定上游操作与 Core 源码候选\n\n| Gateway operation | 当前上游 | Core 候选（语义未验证） |\n| --- | --- | --- |\n${gatewayTable}\n\n来源：${mdPath(upstreamFile)}、${mdPath('TXBoard/api/app/Providers/RouteServiceProvider.php')}、${mdPath('TXBoard/api/routes/txapi.php')}、${mdPath('TXBoard/api/app/Http/Middleware/TxapiUser.php')}。没有匿名支付方法对应候选，不能把现有用户支付方法接口降级成公开入口。\n\n所有“源码存在”仅通过词法注册声明匹配；HTTP route:list、配置/feature flags、实际 Token、DTO 及 Core/Node/Deploy 联调在各实现阶段验证。\n`;

const outputs = new Map([
  ['baseline-inventory.json', `${JSON.stringify(inventory, null, 2)}\n`],
  ['baseline-inventory.md', markdown],
  ['baseline-compatibility-matrix.md', compatibilityMarkdown],
]);
let drift = false;
for (const [file, expected] of outputs) {
  const target = path.join(outputRoot, file);
  if (options.check) {
    let current = null;
    try { current = (await readFile(target, 'utf8')).replaceAll('\r\n', '\n'); } catch (error) { if (error.code !== 'ENOENT') throw error; }
    if (current !== expected) { console.error(`Drift: ${file}`); drift = true; }
  } else {
    await mkdir(outputRoot, { recursive: true });
    await writeFile(target, expected, 'utf8');
  }
}
console.log(`${options.check ? 'Checked' : 'Generated'} B0 inventory: ${JSON.stringify(counts)}`);
for (const name of repoNames) {
  const status = execFileSync('git', ['-C', path.join(options.workspace, name), 'status', '--porcelain=v1', '--untracked-files=all'], { encoding: 'utf8' }).trimEnd();
  console.log(`${name} current working tree: ${status ? 'DIRTY\n' + status : 'clean (at this invocation only)'}`);
}
if (options.check && drift) process.exitCode = 1;
