#!/usr/bin/env bash
# PK95 Core – installation on a Proxmox VE host with one command:
#
#   bash -c "$(curl -fsSL https://raw.githubusercontent.com/PhilippKuhlmann/pk95core/main/install/proxmox.sh)"
#
# Creates an unprivileged Debian 13 LXC (nesting + keyctl for Docker), installs Docker in it and
# starts PK95 Core with the docker-compose.yml of this repository. Everything else (apps such as
# PK95 Proxy, Uptime, ProxApps) is activated afterwards in the web interface.
#
# Settings via environment variables, e.g. a fixed address:
#   CTID=250 IP=10.10.250.50/24 GW=10.10.250.1 bash -c "$(curl -fsSL …/install/proxmox.sh)"
set -euo pipefail

RAW="${PK95_RAW:-https://raw.githubusercontent.com/PhilippKuhlmann/pk95core/main}"
CT_HOSTNAME="${CT_HOSTNAME:-pk95core}"
CORES="${CORES:-2}"
MEMORY="${MEMORY:-2048}"
SWAP="${SWAP:-512}"
DISK="${DISK:-16}"
BRIDGE="${BRIDGE:-vmbr0}"
IP="${IP:-dhcp}"
GW="${GW:-}"
VLAN="${VLAN:-}"
TEMPLATE_STORAGE="${TEMPLATE_STORAGE:-local}"

blue() { printf '\033[1;34m==> %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFehler: %s\033[0m\n' "$*" >&2; exit 1; }

# --- checks --------------------------------------------------------------------------------
[[ $(id -u) -eq 0 ]] || fail "Bitte als root auf dem Proxmox-Host ausführen."
command -v pct >/dev/null && command -v pveam >/dev/null && command -v pvesh >/dev/null \
  || fail "pct/pveam/pvesh fehlen – das Skript läuft nur auf einem Proxmox-VE-Host."

CTID="${CTID:-$(pvesh get /cluster/nextid)}"
pct status "$CTID" >/dev/null 2>&1 && fail "Die CT-ID $CTID ist schon vergeben (CTID=… setzen)."

# Storage for the root disk: given, else local-lvm/local-zfs, else the first one for containers.
if [[ -z "${STORAGE:-}" ]]; then
  storages="$(pvesm status --content rootdir 2>/dev/null | awk 'NR>1 && $3=="active" {print $1}')"
  for s in local-lvm local-zfs; do
    grep -qx "$s" <<<"$storages" && STORAGE="$s" && break
  done
  STORAGE="${STORAGE:-$(head -n1 <<<"$storages")}"
fi
[[ -n "$STORAGE" ]] || fail "Kein Speicher für Container gefunden (STORAGE=… setzen)."

if [[ "$IP" != dhcp ]]; then
  [[ "$IP" == */* ]] || fail "IP bitte mit Präfix angeben, z. B. IP=10.10.250.50/24."
  [[ -n "$GW" ]] || fail "Bei fester IP bitte auch das Gateway angeben (GW=…)."
fi

echo
echo "PK95 Core wird installiert:"
echo "  CT-ID ${CTID} · Hostname ${CT_HOSTNAME} · ${CORES} Kerne · ${MEMORY} MB RAM · ${DISK} GB auf ${STORAGE}"
echo "  Netz ${BRIDGE}${VLAN:+ (VLAN ${VLAN})} · IP ${IP}${GW:+ · Gateway ${GW}}"
# Ask once when a terminal is there (bash -c "$(curl …)" keeps it); without one just go on.
if { exec 3</dev/tty; } 2>/dev/null; then
  read -r -u 3 -p "Fortfahren? [J/n] " answer || answer=j
  exec 3<&-
  [[ "${answer:-j}" =~ ^[JjYy]?$ ]] || fail "Abgebrochen."
fi

# --- Debian template -----------------------------------------------------------------------
blue "Debian-13-Vorlage prüfen"
template="$(pveam list "$TEMPLATE_STORAGE" 2>/dev/null | awk '{print $1}' | grep -o 'debian-13-standard_[^ ]*' | sort -V | tail -n1 || true)"
if [[ -z "$template" ]]; then
  pveam update >/dev/null
  template="$(pveam available --section system | awk '{print $2}' | grep '^debian-13-standard_' | sort -V | tail -n1)"
  [[ -n "$template" ]] || fail "Keine Debian-13-Vorlage verfügbar."
  blue "Lade ${template}"
  pveam download "$TEMPLATE_STORAGE" "$template" >/dev/null
fi

# --- container -----------------------------------------------------------------------------
net="name=eth0,bridge=${BRIDGE},ip=${IP}${GW:+,gw=${GW}}${VLAN:+,tag=${VLAN}}"
blue "Lege LXC ${CTID} an"
pct create "$CTID" "${TEMPLATE_STORAGE}:vztmpl/${template}" \
  --hostname "$CT_HOSTNAME" --cores "$CORES" --memory "$MEMORY" --swap "$SWAP" \
  --rootfs "${STORAGE}:${DISK}" --net0 "$net" --ostype debian \
  --unprivileged 1 --features nesting=1,keyctl=1 --onboot 1 --tags pk95 \
  --description "PK95 Core – zentrale Verwaltung der PK95-Apps (Docker)" >/dev/null
pct start "$CTID"

blue "Warte auf das Netzwerk im Container"
for _ in $(seq 1 60); do
  pct exec "$CTID" -- getent hosts deb.debian.org >/dev/null 2>&1 && break
  sleep 2
done
pct exec "$CTID" -- getent hosts deb.debian.org >/dev/null 2>&1 || fail "Der Container erreicht das Internet nicht (DNS/Gateway prüfen)."

# --- Docker and Core inside the container --------------------------------------------------
blue "Installiere Docker und starte PK95 Core (dauert ein paar Minuten)"
pct exec "$CTID" -- env RAW="$RAW" bash -euo pipefail -c '
export DEBIAN_FRONTEND=noninteractive
apt-get update -q >/dev/null
apt-get install -y -q curl ca-certificates >/dev/null
install -m 0755 -d /etc/apt/keyrings
curl -fsSL --retry 3 https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
. /etc/os-release
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian ${VERSION_CODENAME} stable" \
  > /etc/apt/sources.list.d/docker.list
apt-get update -q >/dev/null
apt-get install -y -q docker-ce docker-ce-cli containerd.io docker-compose-plugin >/dev/null
systemctl enable -q --now docker

mkdir -p /opt/pk95/core /opt/pk95/backups
curl -fsSL --retry 3 -o /opt/pk95/docker-compose.yml "$RAW/docker-compose.yml"
ip="$(hostname -I | awk "{print \$1}")"
[ -f /opt/pk95/.env ] || echo "APP_URL=http://${ip}:8080" > /opt/pk95/.env
cd /opt/pk95
docker compose pull -q
docker compose up -d
'

blue "Warte auf den Einrichtungscode"
code=""
for _ in $(seq 1 60); do
  code="$(pct exec "$CTID" -- docker logs pk95core 2>&1 | grep -o 'Einrichtungscode: [A-Za-z0-9-]*' | tail -n1 | cut -d' ' -f2 || true)"
  [[ -n "$code" ]] && break
  sleep 2
done
ip="$(pct exec "$CTID" -- hostname -I | awk '{print $1}')"

echo
printf '\033[1;32mPK95 Core läuft in LXC %s.\033[0m\n' "$CTID"
echo "  Öffnen:            http://${ip}:8080"
if [[ -n "$code" ]]; then
  echo "  Einrichtungscode:  ${code}"
else
  echo "  Einrichtungscode:  pct exec ${CTID} -- docker logs pk95core | grep Einrichtungscode"
fi
echo
echo "Danach das Admin-Konto anlegen und unter „Apps“ PK95 Proxy, Uptime und ProxApps aktivieren."
