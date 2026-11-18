#!/usr/bin/env python3
"""Minimal Source RCON client for the L4 Docker smoke server.

The probe server enables RCON (see scripts/serve.sh's server.properties), and the
harness uses it for commands that need a console sender: PM commands such as
`form-custom` or `m4packets <player>`, and Nukkit's own `setblock`. The password
is read from the live server.properties so it is never copied into a script.
"""

from __future__ import annotations

import os
import pathlib
import socket
import struct
import sys
import time

HOST = os.environ.get("MUKKIT_RCON_HOST", "127.0.0.1")
PORT = int(os.environ.get("MUKKIT_RCON_PORT", "25575"))
DATA = pathlib.Path(
    os.environ.get("MUKKIT_SMOKE_DATA", "run/docker-probe")
).resolve()


def password() -> str:
    props = DATA / "server.properties"
    for line in props.read_text(encoding="utf-8", errors="replace").splitlines():
        line = line.strip()
        if line.startswith("rcon.") and "=" in line and not line.startswith("rcon.port"):
            return line.split("=", 1)[1]
    raise SystemExit(
        f"no rcon password in {props}; run the probe server once so serve.sh writes it"
    )


def packet(request_id: int, packet_type: int, body: str) -> bytes:
    payload = body.encode("utf-8") + b"\x00\x00"
    return struct.pack("<iii", len(payload) + 8, request_id, packet_type) + payload


def receive(sock: socket.socket) -> tuple[int, int, str]:
    header = b""
    while len(header) < 4:
        chunk = sock.recv(4 - len(header))
        if not chunk:
            raise EOFError("rcon closed the connection")
        header += chunk
    length = struct.unpack("<i", header)[0]
    body = b""
    while len(body) < length:
        chunk = sock.recv(length - len(body))
        if not chunk:
            raise EOFError("rcon closed the connection")
        body += chunk
    request_id, packet_type = struct.unpack("<ii", body[:8])
    return request_id, packet_type, body[8:-2].decode("utf-8", "replace")


def main() -> int:
    command = " ".join(sys.argv[1:]) or "mukkit status"
    sock = socket.create_connection((HOST, PORT), timeout=5)
    sock.sendall(packet(1, 3, password()))
    sock.settimeout(5)
    while True:
        request_id, packet_type, _ = receive(sock)
        if request_id == -1:
            raise SystemExit("rcon authentication failed")
        if request_id == 1 and packet_type == 2:
            break

    sock.sendall(packet(2, 2, command))
    deadline = time.time() + 5
    while time.time() < deadline:
        try:
            request_id, packet_type, body = receive(sock)
        except socket.timeout:
            break
        if request_id == 2 and packet_type == 0:
            if body:
                print(body)
            sock.close()
            return 0
    sock.close()
    raise SystemExit(f"no rcon answer for {command!r}")


if __name__ == "__main__":
    raise SystemExit(main())
