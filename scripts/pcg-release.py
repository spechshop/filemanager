#!/usr/bin/env python3
"""Select one authenticated GitHub release asset without running PHP to bootstrap."""
import json
import re
import sys


def select(release, machine):
    arch = {"x86_64": "x86_64", "amd64": "x86_64", "aarch64": "aarch64", "arm64": "aarch64"}.get(machine)
    if not arch:
        raise ValueError("Arquitetura sem asset PCG conhecido: " + machine)
    if release.get("draft") or release.get("prerelease"):
        raise ValueError("A release atual não é estável")
    tag = release.get("tag_name", "")
    if not re.fullmatch(r"[A-Za-z0-9._-]+", tag):
        raise ValueError("Tag de release inválida")
    assets = [a for a in release.get("assets", [])
              if re.fullmatch(r"pcg729-linux-" + arch + r"-zts(?:-[A-Za-z0-9._-]+)?", a.get("name", ""))]
    if len(assets) != 1:
        raise ValueError("A release atual não possui um único binário PCG ZTS para " + arch)
    asset = assets[0]
    digest = asset.get("digest") or ""
    if not re.fullmatch(r"sha256:[a-fA-F0-9]{64}", digest):
        raise ValueError("SHA-256 ausente ou inválido nos metadados oficiais do PCG")
    url = "https://github.com/spechshop/pcg729/releases/download/" + tag + "/" + asset["name"]
    if asset.get("browser_download_url") != url:
        raise ValueError("Asset fora da origem oficial do PCG")
    size = asset.get("size")
    if not isinstance(size, int) or size <= 0:
        raise ValueError("Tamanho inválido do asset PCG")
    return url, digest[7:].lower(), str(size), tag


if __name__ == "__main__":
    try:
        with open(sys.argv[1], encoding="utf-8") as source:
            print("\t".join(select(json.load(source), sys.argv[2])))
    except (ValueError, KeyError, IndexError, OSError) as error:
        print("Não foi possível selecionar o PCG oficial: " + str(error), file=sys.stderr)
        sys.exit(1)
