"""Loopback-only transparent native Core TLS transport and non-secret observer."""
import hashlib, http.client, http.server, json, os, ssl, time
from pathlib import Path

ADMIN='/txapi/admin/live_revocation_admin'
ALLOWED = {ADMIN+'/users/ban',ADMIN+'/users/7/update','/txapi/node/v1/handshake', '/txapi/node/v1/config',
           '/txapi/node/v1/users', '/txapi/node/v1/report',
           '/txapi/node/v2/usage/submit', '/txapi/node/v2/usage/batch/get'}
root = Path(os.environ['LIVE_REVOCATION_DIR'])

class Proxy(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        return self.forward()
    def do_POST(self):
        return self.forward()
    def do_PUT(self):
        return self.forward()
    def forward(self):
        if self.path not in ALLOWED and not (self.command=='PUT' and self.path.startswith(ADMIN+'/network-nodes/') and self.path.rsplit('/',1)[-1].isdigit()):
            self.send_error(404); return
        length = self.headers.get('Content-Length', '0')
        if not length.isdigit() or int(length) > 1048576:
            self.send_error(413); return
        data = self.rfile.read(int(length))
        if len(data) != int(length):
            self.send_error(400); return
        started = time.time()
        connection = http.client.HTTPConnection('127.0.0.1', int(os.environ['LIVE_REVOCATION_HTTP_PORT']), timeout=5)
        try:
            headers = {k: v for k, v in self.headers.items() if k.lower() not in {'host','connection','transfer-encoding'}}
            connection.request(self.command, self.path, data, headers)
            response = connection.getresponse(); body = response.read(1048577)
            if len(body) > 1048576:
                self.send_error(502); return
            self.send_response(response.status)
            for key, value in response.getheaders():
                if key.lower() not in {'connection','transfer-encoding','content-length'}:
                    self.send_header(key, value)
            self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
            record = {'path': self.path, 'method': self.command, 'status': response.status,
                      'started_at': started, 'completed_at': time.time(),
                      'request_sha256': hashlib.sha256(data).hexdigest(),
                      'response_sha256': hashlib.sha256(body).hexdigest(),'etag':response.getheader('ETag'),'if_none_match':self.headers.get('If-None-Match')}
            if self.path.endswith('/users') and response.status==200:
                snapshot=json.loads(body).get('data',{});record['user_count']=len(snapshot.get('users',[]));record['user_ids']=[u['id']for u in snapshot.get('users',[])]
            if self.path.endswith('/config') and response.status==200:
                record['server_port']=json.loads(body).get('data',{}).get('config',{}).get('server_port')
            if self.path == '/txapi/node/v1/report':
                request = json.loads(data); ack = json.loads(body).get('data', {})
                record.update({'traffic_present': bool(request.get('traffic')),
                               'traffic_batch_present': bool(request.get('traffic_batch_id')),
                               'protocol_version': ack.get('protocol_version'),
                               'accepted': ack.get('accepted') is True,
                               'settlement': ack.get('settlement')})
            with (root / 'native-observations.jsonl').open('a', encoding='utf-8') as output:
                output.write(json.dumps(record, sort_keys=True) + '\n')
                output.flush(); os.fsync(output.fileno())
        finally:
            connection.close()
    def log_message(self, *args):
        pass

server = http.server.HTTPServer(('127.0.0.1', int(os.environ['LIVE_REVOCATION_TLS_PORT'])), Proxy)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); context.minimum_version = ssl.TLSVersion.TLSv1_3
context.load_cert_chain(root / 'cert.pem', root / 'key.pem')
server.socket = context.wrap_socket(server.socket, server_side=True)
server.serve_forever()