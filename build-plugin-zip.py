#!/usr/bin/env python3
"""Genera searchagencias-monitor-vX.Y.Z.zip listo para WordPress."""

from __future__ import annotations

import re
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent
PLUGIN_DIR = ROOT / "searchagencias-monitor"
MAIN_FILE = PLUGIN_DIR / "searchagencias-monitor.php"


def read_header_version() -> str:
    text = MAIN_FILE.read_text(encoding="utf-8")
    match = re.search(r"^\s*\*\s*Version:\s*(.+)$", text, re.MULTILINE)
    if not match:
        raise SystemExit(f"No se encontró * Version: en {MAIN_FILE}")
    return match.group(1).strip()


def read_constant_version() -> str:
    text = MAIN_FILE.read_text(encoding="utf-8")
    match = re.search(
        r"define\(\s*'SEARCHAGENCIAS_MONITOR_VERSION'\s*,\s*'([^']+)'\s*\)",
        text,
    )
    if not match:
        raise SystemExit("No se encontró SEARCHAGENCIAS_MONITOR_VERSION")
    return match.group(1).strip()


def build_zip(version: str) -> Path:
    out = ROOT / f"searchagencias-monitor-v{version}.zip"
    if out.exists():
        out.unlink()
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as zf:
        for path in sorted(PLUGIN_DIR.rglob("*")):
            if not path.is_file():
                continue
            arcname = path.relative_to(PLUGIN_DIR.parent).as_posix()
            zf.write(path, arcname)
    with zipfile.ZipFile(out) as zf:
        main_entry = "searchagencias-monitor/searchagencias-monitor.php"
        if main_entry not in zf.namelist():
            raise SystemExit(f"ZIP incorrecto: falta {main_entry}")
    return out


def main() -> None:
    header = read_header_version()
    constant = read_constant_version()
    if header != constant:
        raise SystemExit(f"Versiones desincronizadas: {header} vs {constant}")
    out = build_zip(header)
    print(f"OK  plugin v{header}")
    print(f"ZIP {out}")


if __name__ == "__main__":
    main()
