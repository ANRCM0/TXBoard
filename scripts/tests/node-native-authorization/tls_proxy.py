"""Trusted transparent same-origin facade with explicit loopback transport faults.

It forwards original HTTP/WS bytes and only refuses selected native requests.
Observation never records bearer headers, UUIDs, private keys or envelope bodies.
"""
import base64
import hashlib
import http.client
import json
import select
import socket
import socketserver
import ssl
import threading
import time


class NativeAuthorizationTLSProxy:
    def __init__(self, port, http_port, ws_port, cert, key, observation):
        self.http_port, self.ws_port, self.observation = http_port, ws_port, observation
        self.lock = threading.Lock()
        self.events, self.tunnels, self.blocked_users = [], {}, set()
        self.offline = False
        owner = self

        class Handler(socketserver.BaseRequestHandler):
            def handle(self):
                client = self.request
                client.settimeout(12)
                raw = b''
                while b'\r\n\r\n' not in raw:
                    chunk = client.recv(4096)
                    if not chunk or len(raw) > 65536:
                        return
                    raw += chunk
                header, body = raw.split(b'\r\n\r\n', 1)
                lines = header.decode('iso-8859-1').split('\r\n')
                method, path, _ = lines[0].split(' ', 2)
                headers = dict(line.split(':', 1) for line in lines[1:] if ':' in line)
                headers = {key.strip(): value.strip() for key, value in headers.items()}
                lower = {key.lower(): value for key, value in headers.items()}
                node_id, machine_id = lower.get('x-tx-node-id'), lower.get('x-tx-machine-id')
                native = path.startswith('/txapi/node/v1/') and (node_id or machine_id)
                with owner.lock:
                    blocked = native and (owner.offline or (path == '/txapi/node/v1/users' and node_id in owner.blocked_users))
                if blocked:
                    owner.record({'kind': 'native-offline', 'path': path, 'node_id': node_id, 'machine_id': machine_id})
                    payload = b'{"error":{"code":"ISOLATED_AUTHORIZATION_TRANSPORT","message":"Synthetic local transport fault"}}'
                    client.sendall(b'HTTP/1.1 503 Service Unavailable\r\nContent-Type: application/json\r\nConnection: close\r\n'
                                   + f'Content-Length: {len(payload)}\r\n\r\n'.encode() + payload)
                    return
                if lower.get('upgrade', '').lower() == 'websocket':
                    if path != '/txapi/node/v1/ws':
                        return
                    self.tunnel(client, raw, node_id, machine_id)
                    return
                length = int(lower.get('content-length', '0'))
                if length < 0 or length > 4 << 20:
                    return
                while len(body) < length:
                    chunk = client.recv(min(65536, length - len(body)))
                    if not chunk:
                        return
                    body += chunk
                headers['Connection'] = 'close'
                upstream = http.client.HTTPConnection('127.0.0.1', owner.http_port, timeout=10)
                try:
                    upstream.request(method, path, body[:length] if length else None, headers)
                    response = upstream.getresponse()
                    content = response.read(4 << 20)
                    event = {'kind': 'http', 'method': method, 'path': path.split('?', 1)[0],
                             'status': response.status, 'node_id': node_id, 'machine_id': machine_id}
                    if path == '/txapi/node/v1/users' and response.status == 200:
                        document = json.loads(content)
                        document = document.get('data', document)
                        envelope = document.get('authorization', {})
                        if envelope.get('payload'):
                            signed = base64.urlsafe_b64decode(envelope['payload'] + '=' * (-len(envelope['payload']) % 4))
                            payload = json.loads(signed)
                            event['authorization'] = {name: payload[name] for name in
                                ('node_id', 'machine_id', 'revision', 'key_epoch', 'issued_at', 'expires_at', 'complete')}
                            event['authorization']['user_count'] = len(payload['users'])
                            event['authorization']['payload_sha256'] = hashlib.sha256(signed).hexdigest()
                            event['cache_control'] = response.getheader('Cache-Control', '')
                    if path == '/txapi/node/v1/report':
                        report = json.loads(body[:length] or b'{}')
                        event.update(batch_id=report.get('traffic_batch_id'), traffic=report.get('traffic', {}),
                                     traffic_segments=report.get('traffic_segments'))
                        if content:
                            event['error_code'] = json.loads(content).get('error', {}).get('code')
                    owner.record(event)
                    output = f'HTTP/1.1 {response.status} {response.reason}\r\n'.encode()
                    output += b''.join(f'{key}: {value}\r\n'.encode('iso-8859-1') for key, value in response.getheaders()
                                       if key.lower() not in {'connection', 'transfer-encoding', 'content-length'})
                    client.sendall(output + f'Content-Length: {len(content)}\r\nConnection: close\r\n\r\n'.encode() + content)
                finally:
                    upstream.close()

            def tunnel(self, client, raw, node_id, machine_id):
                with socket.create_connection(('127.0.0.1', owner.ws_port), timeout=5) as upstream:
                    upstream.sendall(raw)
                    response = b''
                    while b'\r\n\r\n' not in response:
                        part = upstream.recv(4096)
                        if not part:
                            return
                        response += part
                        if len(response) > 65536:
                            return
                    status = int(response.split(b' ', 2)[1])
                    owner.record({'kind': 'ws-upgrade', 'status': status, 'node_id': node_id, 'machine_id': machine_id})
                    client.sendall(response)
                    if status != 101:
                        return
                    _, initial_frames = response.split(b'\r\n\r\n', 1)
                    frames = initial_frames
                    client.settimeout(None)
                    upstream.settimeout(None)
                    tunnel_id = id(client)
                    with owner.lock:
                        owner.tunnels[tunnel_id] = (client, upstream)
                    try:
                        while True:
                            frames = owner.observe_frames(frames, machine_id)
                            readable, _, _ = select.select([client, upstream], [], [], 1)
                            for source in readable:
                                target = upstream if source is client else client
                                data = source.recv(65536)
                                if not data:
                                    return
                                target.sendall(data)
                                if source is upstream:
                                    frames += data
                    except (OSError, ssl.SSLError):
                        pass
                    finally:
                        with owner.lock:
                            owner.tunnels.pop(tunnel_id, None)
                        owner.record({'kind': 'ws-close', 'machine_id': machine_id})

        class Server(socketserver.ThreadingTCPServer):
            allow_reuse_address = True
            daemon_threads = True

        self.server = Server(('127.0.0.1', port), Handler)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(cert, key)
        self.server.socket = context.wrap_socket(self.server.socket, server_side=True)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)

    def observe_frames(self, buffer, machine_id):
        while len(buffer) >= 2:
            first, second = buffer[0], buffer[1]
            size, offset = second & 127, 2
            if size == 126:
                if len(buffer) < 4:
                    break
                size, offset = int.from_bytes(buffer[2:4], 'big'), 4
            elif size == 127:
                if len(buffer) < 10:
                    break
                size, offset = int.from_bytes(buffer[2:10], 'big'), 10
            if second & 128 or size > 4 << 20:
                return b''
            if len(buffer) < offset + size:
                break
            payload, buffer = buffer[offset:offset + size], buffer[offset + size:]
            if first & 15 != 1 or not first & 128:
                continue
            try:
                document = json.loads(payload)
                event = document.get('event', '')
                if event in ('sync.users', 'sync.user.delta', 'session.ready'):
                    data = document.get('data', {})
                    self.record({'kind': 'ws-frame', 'event': event, 'machine_id': machine_id,
                                 'node_id': data.get('node_id', document.get('node_id'))})
            except (ValueError, TypeError, AttributeError):
                pass
        return buffer

    def record(self, event):
        event['monotonic_ns'] = time.monotonic_ns()
        with self.lock:
            self.events.append(event)
            with open(self.observation, 'a', encoding='utf-8') as output:
                output.write(json.dumps(event, sort_keys=True) + '\n')
                output.flush()

    def event_snapshot(self):
        with self.lock:
            return [dict(event) for event in self.events]

    def block_users(self, node_id, enabled):
        with self.lock:
            if enabled:
                self.blocked_users.add(str(node_id))
            else:
                self.blocked_users.discard(str(node_id))

    def set_offline(self, enabled):
        with self.lock:
            self.offline = enabled
            targets = list(self.tunnels.values()) if enabled else []
        for client, upstream in targets:
            for connection in (client, upstream):
                try:
                    connection.shutdown(socket.SHUT_RDWR)
                except OSError:
                    pass
        self.record({'kind': 'offline-switch', 'enabled': enabled})

    def start(self):
        self.thread.start()

    def close(self):
        self.set_offline(True)
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=2)
