#!/usr/bin/env python3
"""Minimal beanstalkd wire-protocol client, used by scripts/demo.sh.

It deliberately uses no client library: the point of the demo is that
beanstalkd-rs speaks the reference's protocol, so the demo speaks it too.

Usage:
    demo-client.py flow  HOST:PORT   producer, worker, bury, tube stats
    demo-client.py state HOST:PORT   job counts and tube list (after a restart)
    demo-client.py count HOST:PORT   prints current-jobs-ready only
"""

from __future__ import annotations

import socket
import sys


class Conn:
    """One connection; replies are read exactly as the protocol frames them."""

    def __init__(self, addr: str) -> None:
        host, port = addr.rsplit(":", 1)
        self.sock = socket.create_connection((host, int(port)), 5)
        self.sock.settimeout(5)
        self.buf = b""

    def send(self, line: str, body: bytes = b"") -> None:
        wire = line.encode() + b"\r\n"
        if body:
            wire += body + b"\r\n"
        self.sock.sendall(wire)

    def _fill(self, n: int) -> None:
        while len(self.buf) < n:
            chunk = self.sock.recv(65536)
            if not chunk:
                raise EOFError("the server closed the connection")
            self.buf += chunk

    def _line(self) -> bytes:
        while b"\r\n" not in self.buf:
            self._fill(len(self.buf) + 1)
        line, self.buf = self.buf.split(b"\r\n", 1)
        return line

    def _bytes(self, n: int) -> bytes:
        self._fill(n)
        out, self.buf = self.buf[:n], self.buf[n:]
        return out

    def reply(self) -> tuple[bytes, bytes | None]:
        """Returns (first line, body) for the three framed reply shapes."""
        line = self._line()
        if line.startswith(b"RESERVED "):
            size = int(line.split()[2])
            return line, self._bytes(size + 2)[:-2]
        if line.startswith(b"OK "):
            size = int(line.split()[1])
            return line, self._bytes(size + 2)[:-2]
        return line, None

    def call(self, line: str, body: bytes = b"") -> tuple[bytes, bytes | None]:
        self.send(line, body)
        return self.reply()

    def close(self) -> None:
        try:
            self.sock.sendall(b"quit\r\n")
        except OSError:
            pass
        self.sock.close()


def show(actor: str, command: str, line: bytes, body: bytes | None = None) -> None:
    text = line.decode()
    print(f"  {actor:<9}{command:<30}-> {text}")
    if body is not None:
        print(f"  {'':<9}{'body':<30}-> {body.decode()}")


def yaml_fields(body: bytes) -> dict[str, str]:
    fields: dict[str, str] = {}
    for line in body.decode().splitlines():
        if line == "---":
            continue
        key, sep, value = line.partition(": ")
        if sep:
            fields[key] = value
    return fields


def flow(addr: str) -> None:
    producer = Conn(addr)
    worker = Conn(addr)
    monitor = Conn(addr)

    show("producer", "use demo", producer.call("use demo")[0])
    show("worker", "watch demo", worker.call("watch demo")[0])
    show("worker", "ignore default", worker.call("ignore default")[0])

    ids = []
    for pri, body in ((0, b"render invoice 42"), (100, b"send digest"), (200, b"reindex 7")):
        line, _ = producer.call(f"put {pri} 0 60 {len(body)}", body)
        show("producer", f"put {pri} 0 60 {len(body)}", line)
        ids.append(int(line.split()[1]))

    line, body = worker.call("reserve")
    show("worker", "reserve", line, body)
    show("worker", f"delete {ids[0]}", worker.call(f"delete {ids[0]}")[0])

    line, body = worker.call("reserve")
    show("worker", "reserve", line, body)
    show("worker", f"bury {ids[1]} 0", worker.call(f"bury {ids[1]} 0")[0])

    line, body = monitor.call("stats-tube demo")
    fields = yaml_fields(body or b"")
    print(f"  {'monitor':<9}{'stats-tube demo':<30}-> "
          f"ready {fields.get('current-jobs-ready')}, "
          f"buried {fields.get('current-jobs-buried')}, "
          f"reserved {fields.get('current-jobs-reserved')}")

    for conn in (producer, worker, monitor):
        conn.close()


def state(addr: str) -> None:
    conn = Conn(addr)
    line, body = conn.call("stats")
    fields = yaml_fields(body or b"")
    for key in ("current-jobs-ready", "current-jobs-reserved", "current-jobs-buried", "current-jobs-delayed"):
        print(f"  {key:<26}{fields.get(key)}")
    line, body = conn.call("list-tubes")
    tubes = [t[2:] for t in (body or b"").decode().splitlines() if t.startswith("- ")]
    print(f"  {'list-tubes':<26}{', '.join(tubes)}")
    conn.close()


def count(addr: str) -> None:
    conn = Conn(addr)
    line, body = conn.call("stats")
    print(yaml_fields(body or b"").get("current-jobs-ready"))
    conn.close()


def main() -> int:
    modes = {"flow": flow, "state": state, "count": count}
    if len(sys.argv) != 3 or sys.argv[1] not in modes:
        print(__doc__, file=sys.stderr)
        return 2
    modes[sys.argv[1]](sys.argv[2])
    return 0


if __name__ == "__main__":
    sys.exit(main())
