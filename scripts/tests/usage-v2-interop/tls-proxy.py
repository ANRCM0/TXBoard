import os, ssl, http.server, http.client
class Proxy(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        size=int(self.headers.get('Content-Length','0'))
        if size>1048576:
            self.send_response(413); self.end_headers(); return
        body=self.rfile.read(size)
        conn=http.client.HTTPConnection('127.0.0.1',18081,timeout=10)
        conn.request('POST',self.path,body,{k:v for k,v in self.headers.items() if k.lower()!='host'})
        res=conn.getresponse(); data=res.read()
        self.send_response(res.status)
        for k,v in res.getheaders():
            if k.lower() not in ['connection','transfer-encoding','content-length']: self.send_header(k,v)
        self.send_header('Content-Length',str(len(data))); self.end_headers(); self.wfile.write(data); conn.close()
    def log_message(self,*args): pass
server=http.server.HTTPServer(('127.0.0.1',18443),Proxy)
ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.minimum_version=ssl.TLSVersion.TLSv1_2
ctx.load_cert_chain(os.environ['USAGE_INTEROP_DIR']+'/cert.pem',os.environ['USAGE_INTEROP_DIR']+'/key.pem')
server.socket=ctx.wrap_socket(server.socket,server_side=True)
server.serve_forever()