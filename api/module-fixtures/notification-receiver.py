#!/usr/bin/env python3
"""HTTPS proof receiver: commit an idempotent event receipt before acknowledging it.

The optional first lost acknowledgement is persisted by the receipt itself, so
restarting this process cannot turn a retry into a second accepted event.
"""
import argparse
from contextlib import closing
import hashlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import re
import secrets
import socket
import sqlite3
import ssl
import time


def canonical(value):
    return json.dumps(value, sort_keys=True, ensure_ascii=True, separators=(",", ":"))


def unique(items):
    result = {}
    for key, value in items:
        if key in result:
            raise ValueError("duplicate key")
        result[key] = value
    return result


class Receiver(BaseHTTPRequestHandler):
    server_version = "ModuleProofReceiver/1"

    def log_message(self, *_args):
        pass

    def respond(self, status, value):
        data = canonical(value).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(data)

    def authorized(self):
        return secrets.compare_digest(self.headers.get("Authorization", ""), "Bearer " + self.server.token)

    def do_GET(self):
        if self.path == "/health":
            self.respond(200, {"status": "ok"})
            return
        if not self.authorized():
            self.respond(401, {"error": "UNAUTHORIZED"})
            return
        event_id = self.path.removeprefix("/events/")
        if self.path != "/events/" + event_id or not re.fullmatch(r"[0-9a-f]{64}", event_id):
            self.respond(404, {"error": "NOT_FOUND"})
            return
        with closing(sqlite3.connect(self.server.store)) as database:
            row = database.execute("SELECT body_hash FROM event_receipt WHERE event_id=?", (event_id,)).fetchone()
            count = database.execute("SELECT COUNT(*) FROM event_receipt").fetchone()[0]
        self.respond(200 if row else 404, {"event_id": event_id, "accepted_count": count, "status": "accepted" if row else "unknown"})

    def do_POST(self):
        if not self.authorized():
            self.respond(401, {"error": "UNAUTHORIZED"})
            return
        if self.path != "/events":
            self.respond(404, {"error": "NOT_FOUND"})
            return
        try:
            size = int(self.headers.get("Content-Length", "-1"))
            if not 0 < size <= 65536 or self.headers.get("Transfer-Encoding") is not None:
                raise ValueError("size")
            raw = self.rfile.read(size)
            if len(raw) != size:
                raise ValueError("length")
            event = json.loads(raw, object_pairs_hook=unique)
            if not isinstance(event, dict) or set(event) != {"event_id", "installation_id", "kind", "business_ref", "occurred_at", "data"}:
                raise ValueError("fields")
            event_id = event["event_id"]
            if not isinstance(event_id, str) or not re.fullmatch(r"[0-9a-f]{64}", event_id) or self.headers.get("Idempotency-Key") != event_id:
                raise ValueError("event")
            body = canonical(event)
            body_hash = hashlib.sha256(body.encode()).hexdigest()
        except (ValueError, UnicodeError, RecursionError):
            self.respond(400, {"error": "EVENT_INVALID"})
            return
        with closing(sqlite3.connect(self.server.store, timeout=10, isolation_level=None)) as database:
            database.execute("PRAGMA synchronous=FULL")
            database.execute("BEGIN IMMEDIATE")
            row = database.execute("SELECT body_hash FROM event_receipt WHERE event_id=?", (event_id,)).fetchone()
            if row is not None and row[0] != body_hash:
                database.rollback()
                self.respond(409, {"error": "EVENT_CONFLICT"})
                return
            fresh = row is None
            if fresh:
                database.execute("INSERT INTO event_receipt(event_id,body_hash,body,accepted_at) VALUES(?,?,?,?)",
                                 (event_id, body_hash, body, int(time.time())))
            database.commit()
        if fresh and self.server.lose_first_ack:
            self.close_connection = True
            self.connection.shutdown(socket.SHUT_RDWR)
            self.connection.close()
            return
        self.respond(200, {"event_id": event_id, "status": "accepted"})


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--port", type=int, default=0)
    parser.add_argument("--certificate", type=Path, required=True)
    parser.add_argument("--private-key", type=Path, required=True)
    parser.add_argument("--token-file", type=Path, required=True)
    parser.add_argument("--store", type=Path, required=True)
    parser.add_argument("--lose-first-ack", action="store_true")
    args = parser.parse_args()
    token = args.token_file.read_text().strip()
    if not 16 <= len(token) <= 1024 or any(character.isspace() for character in token):
        raise SystemExit("RECEIVER_TOKEN_INVALID")
    with closing(sqlite3.connect(args.store)) as database:
        database.execute("PRAGMA journal_mode=WAL")
        database.execute("PRAGMA synchronous=FULL")
        database.execute("CREATE TABLE IF NOT EXISTS event_receipt(event_id TEXT PRIMARY KEY,body_hash TEXT NOT NULL,body TEXT NOT NULL,accepted_at INTEGER NOT NULL)")
        database.commit()
    server = ThreadingHTTPServer(("127.0.0.1", args.port), Receiver)
    server.token, server.store, server.lose_first_ack = token, args.store, args.lose_first_ack
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.load_cert_chain(args.certificate, args.private_key)
    server.socket = context.wrap_socket(server.socket, server_side=True)
    print(canonical({"listening": True, "port": server.server_port}), flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
