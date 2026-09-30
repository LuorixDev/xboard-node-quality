#!/usr/bin/env bash
set -euo pipefail
panel= enroll= action=install
while (($#)); do
 case "$1" in
  --panel) panel="${2:?}"; shift 2;;
  --enroll-token) enroll="${2:?}"; shift 2;;
  --uninstall) action=uninstall; shift;;
  *) echo 'Usage: install.sh --panel https://panel --enroll-token CODE | --uninstall' >&2; exit 2;;
 esac
done
[[ $EUID == 0 ]] || { echo 'Run with sudo.' >&2; exit 1; }
if [[ $action == uninstall ]]; then
 systemctl disable --now node-quality-agent.service || true
 rm -f /etc/systemd/system/node-quality-agent.service
 systemctl daemon-reload
 echo 'Service removed. Agent binaries and /var/lib/node-quality retained.'
 exit 0
fi
[[ $panel =~ ^https://[a-zA-Z0-9.-]+(:[0-9]+)?/?$ && $enroll =~ ^[a-f0-9]{64}$ ]] || { echo 'Valid HTTPS panel origin and enrollment code required.' >&2; exit 2; }
. /etc/os-release
[[ $ID == debian || $ID == ubuntu ]] || { echo 'Supported OS: Debian / Ubuntu' >&2; exit 1; }
case "$(uname -m)" in x86_64) arch=amd64;; aarch64|arm64) arch=arm64;; *) echo 'Supported CPU: amd64 / arm64'; exit 1;; esac
command -v systemctl >/dev/null
apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends ca-certificates curl jq bc iputils-ping coreutils dnsutils
tmp=$(mktemp -d); was_active=0
cleanup(){ local rc=$?; rm -rf "$tmp"; if ((rc != 0 && was_active == 1)); then systemctl start node-quality-agent.service || true; fi; }
trap cleanup EXIT
base="${panel%/}/plugins/node_quality"
curl --fail --silent --show-error --proto '=https' "$base/manifest.json" -o "$tmp/manifest.json"
fetch() {
 local name="$1" hash="$2" url="$3"
 [[ $hash =~ ^[a-f0-9]{64}$ ]] || { echo 'Missing valid checksum' >&2; exit 1; }
 curl --fail --location --silent --show-error --proto '=https' --proto-redir '=https' "$url" -o "$tmp/$name"
 printf '%s  %s\n' "$hash" "$tmp/$name" | sha256sum -c - >/dev/null
}
for name in "node-quality-agent-linux-$arch.gz" collect-quality.sh nexttrace.json; do
 hash=$(jq -r --arg n "$name" '.files[$n]' "$tmp/manifest.json")
 fetch "$name" "$hash" "$base/$name"
done
hash=$(jq -r --arg a "$arch" '.assets[$a].sha256' "$tmp/nexttrace.json")
url=$(jq -r --arg a "$arch" '.assets[$a].url' "$tmp/nexttrace.json")
fetch nexttrace "$hash" "$url"
gzip -dc "$tmp/node-quality-agent-linux-$arch.gz" > "$tmp/agent"
chmod 0755 "$tmp/agent" "$tmp/nexttrace"
"$tmp/agent" version
id nodequality >/dev/null 2>&1 || useradd --system --home-dir /var/lib/node-quality --shell /usr/sbin/nologin nodequality
install -d -m 0755 /opt/node-quality
install -d -m 0700 -o nodequality -g nodequality /var/lib/node-quality
if systemctl is-active --quiet node-quality-agent; then was_active=1; systemctl stop node-quality-agent; fi
install -m 0755 "$tmp/agent" /opt/node-quality/node-quality-agent
install -m 0755 "$tmp/nexttrace" /opt/node-quality/nexttrace
install -m 0644 "$tmp/collect-quality.sh" /opt/node-quality/collect-quality.sh
runuser -u nodequality -- /opt/node-quality/node-quality-agent enroll --panel "$panel" --enroll-token "$enroll"
cat > /etc/systemd/system/node-quality-agent.service <<'UNIT'
[Unit]
Description=XBoard Node Quality Agent
After=network-online.target
Wants=network-online.target
[Service]
Type=simple
User=nodequality
Group=nodequality
WorkingDirectory=/var/lib/node-quality
ExecStart=/opt/node-quality/node-quality-agent run
Restart=on-failure
RestartSec=10
TimeoutStopSec=15
UMask=0077
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
ReadWritePaths=/var/lib/node-quality
AmbientCapabilities=CAP_NET_RAW
CapabilityBoundingSet=CAP_NET_RAW
MemoryMax=256M
TasksMax=64
CPUQuota=50%
[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now node-quality-agent.service
echo 'Installed. Bind panel nodes to this probe in Node Quality administration.'
