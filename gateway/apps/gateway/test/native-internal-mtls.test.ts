import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { execFileSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { createServer, type Server } from 'node:https'
import { NativeCoreClient, type NativeServiceConfig } from '../src/services/native-internal.js'

// CI only: real certificates and TLS sockets. No pre-generated private keys,
// insecure TLS environment flags, network dependencies or fake transport.
describe('native HTTPS service transport', () => {
  let directory: string
  let server: Server
  let origin: URL
  let service: NativeServiceConfig
  let mode: 'normal' | 'trickle' = 'normal'
  const uuid = '11111111-1111-4111-8111-111111111111'
  beforeAll(async () => {
    directory = mkdtempSync(join(tmpdir(), 'tx-native-mtls-'))
    const openssl = (args: string[]) => execFileSync('openssl', args, { cwd: directory, stdio: 'ignore' })
    openssl(['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', 'ca.key', '-out', 'ca.pem',
      '-days', '2', '-subj', '/CN=Native test CA', '-addext', 'basicConstraints=critical,CA:TRUE'])
    for (const name of ['server', 'client']) {
      openssl(['req', '-newkey', 'rsa:2048', '-nodes', '-keyout', `${name}.key`, '-out', `${name}.csr`,
        '-subj', `/CN=${name === 'server' ? 'localhost' : 'user-gateway'}`])
      writeFileSync(join(directory, `${name}.ext`), name === 'server'
        ? 'subjectAltName=DNS:localhost\nextendedKeyUsage=serverAuth\n'
        : 'extendedKeyUsage=clientAuth\n')
      openssl(['x509', '-req', '-in', `${name}.csr`, '-CA', 'ca.pem', '-CAkey', 'ca.key', '-CAcreateserial',
        '-out', `${name}.pem`, '-days', '2', '-extfile', `${name}.ext`])
    }
    service = { domain: 'user', serviceId: 'user-gateway', credential: 'a'.repeat(64), epoch: '1',
      caFile: join(directory, 'ca.pem'), certificateFile: join(directory, 'client.pem'), keyFile: join(directory, 'client.key') }
    server = createServer({
      key: readFileSync(join(directory, 'server.key')), cert: readFileSync(join(directory, 'server.pem')),
      ca: readFileSync(join(directory, 'ca.pem')), requestCert: true, rejectUnauthorized: true,
    }, (request, response) => {
      expect(request.headers['x-core-service-credential']).toBe(service.credential)
      expect(request.headers['x-core-tls-verified']).toBeUndefined()
      expect(request.url).toBe('/txapi/internal/native/v1/user/operations/system.capabilities.get')
      response.writeHead(200, { 'Content-Type': 'application/json' })
      if (mode === 'trickle') {
        response.write('{"data":"')
        const interval = setInterval(() => response.write('x'), 25)
        response.on('close', () => clearInterval(interval))
        return
      }
      response.end(JSON.stringify({ request_id: uuid, data: { contract: 'native-internal-api/v1' } }))
    })
    // Listen on both IP families; TLS verifies localhost's DNS SAN.
    await new Promise<void>(resolve => server.listen(0, resolve))
    const address = server.address()
    if (!address || typeof address === 'string') throw new Error('TLS_LISTENER_FAILED')
    origin = new URL(`https://localhost:${address.port}/`)
  }, 30000)
  afterAll(async () => {
    if (server) await new Promise<void>(resolve => server.close(() => resolve()))
    if (directory) rmSync(directory, { recursive: true, force: true })
  })
  it('presents the configured client certificate and verifies the private server CA', async () => {
    const result = await new NativeCoreClient(origin, service).call('system.capabilities.get')
    expect(result.data).toEqual({ contract: 'native-internal-api/v1' })
  })
  it('rejects a valid certificate for the wrong server hostname', async () => {
    const wrongHost = new URL(origin)
    wrongHost.hostname = '127.0.0.1'
    await expect(new NativeCoreClient(wrongHost, service).call('system.capabilities.get'))
      .rejects.toMatchObject({ status: 503 })
  })
  it('rejects an untrusted server CA without exposing TLS details', async () => {
    await expect(new NativeCoreClient(origin, { ...service, caFile: join(directory, 'client.pem') })
      .call('system.capabilities.get')).rejects.toMatchObject({
        status: 503, message: 'Core result unknown; retain the original idempotency key',
      })
  })
  it('enforces the byte cap on the actual TLS response stream', async () => {
    await expect(new NativeCoreClient(origin, service, 8000, 32).call('system.capabilities.get'))
      .rejects.toMatchObject({ status: 502, message: 'Core response limit exceeded' })
  })
  it('aborts a continuously trickling response at the wall-clock deadline without retry', async () => {
    mode = 'trickle'
    try {
      await expect(new NativeCoreClient(origin, service, 500).call('system.capabilities.get'))
        .rejects.toMatchObject({
          status: 503, message: 'Core result unknown; retain the original idempotency key',
        })
    } finally {
      mode = 'normal'
    }
  })
})
