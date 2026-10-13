import http.client, http.server, os, ssl

ALLOWED={'/txapi/node/v1/handshake','/txapi/node/v1/config','/txapi/node/v1/users','/txapi/node/v1/report','/txapi/node/v2/usage/submit','/txapi/node/v2/usage/batch/get'}

class Proxy(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        return self.forward()
    def do_POST(self):
        return self.forward()
    def forward(self):
        if self.path not in ALLOWED:
            self.send_error(404); return
        raw=self.headers.get('Content-Length','0')
        if not raw.isdigit() or int(raw)>1048576:
            self.send_error(413); return
        data=self.rfile.read(int(raw))
        if len(data)!=int(raw):
            self.send_error(400); return
        connection=http.client.HTTPConnection('127.0.0.1',int(os.environ['SERVICE_USAGE_HTTP_PORT']),timeout=5)
        try:
            headers={k:v for k,v in self.headers.items() if k.lower() not in {'host','connection','transfer-encoding'}}
            connection.request(self.command,self.path,data,headers)
            response=connection.getresponse();body=response.read(1048577)
            if len(body)>1048576:
                self.send_error(502); return
            self.send_response(response.status)
            for k,v in response.getheaders():
                if k.lower() not in {'connection','transfer-encoding','content-length'}: self.send_header(k,v)
            self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
        finally:
            connection.close()
    def log_message(self,*args): pass

server=http.server.HTTPServer(('127.0.0.1',int(os.environ['SERVICE_USAGE_TLS_PORT'])),Proxy)
context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.minimum_version=ssl.TLSVersion.TLSv1_3
context.load_cert_chain(os.environ['SERVICE_USAGE_DIR']+'/cert.pem',os.environ['SERVICE_USAGE_DIR']+'/key.pem')
server.socket=context.wrap_socket(server.socket,server_side=True)
server.serve_forever()
