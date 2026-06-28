#!/usr/bin/env bash
# Workaround for corporate/VPN networks where the Podman (WSL) VM loses DNS even
# though the Windows host has internet. Points the VM's /etc/resolv.conf at the
# WSL NAT gateway, which proxies the host resolver (public resolvers like 8.8.8.8
# are often blocked on corporate networks).
#
# Re-run this if ./bin/test.sh / ./bin/coverage.sh / ./bin/e2e.sh fail with
# "Could not resolve host ...". Windows/WSL + Podman specific; harmless to skip
# on machines where DNS already works.
set -uo pipefail

if ! command -v podman >/dev/null 2>&1; then
  echo "podman not found on PATH; nothing to do."
  exit 0
fi

podman machine ssh '
  GW=$(ip route show default | awk "{print \$3}" | head -1)
  if [ -z "$GW" ]; then echo "Could not determine gateway"; exit 1; fi
  sudo sh -c "printf \"nameserver $GW\nnameserver 8.8.8.8\n\" > /etc/resolv.conf"
  echo "VM DNS set to gateway $GW (fallback 8.8.8.8)"
  if getent hosts api.wordpress.org >/dev/null 2>&1; then
    echo "DNS OK (api.wordpress.org resolves)"
  else
    echo "WARNING: DNS still failing after fix"
  fi
'
