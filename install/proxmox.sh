#!/usr/bin/env bash
# PK95 Core – installation on a Proxmox VE host with one command:
#
#   bash -c "$(curl -fsSL https://raw.githubusercontent.com/PhilippKuhlmann/pk95core/main/install/proxmox.sh)"
#
# Creates an unprivileged Debian 13 LXC (nesting + keyctl for Docker), installs Docker in it and
# starts PK95 Core with the docker-compose.yml of this repository. Everything else (apps such as
# PK95 Proxy, Uptime, ProxApps) is activated afterwards in the web interface.
#
# Asks step by step for ID, hostname, resources, storage and network (Enter keeps the suggestion).
# Environment variables become the suggestions, e.g. CTID=250 IP=10.10.250.50/24 GW=10.10.250.1;
# without a terminal the script runs unattended with them.
set -euo pipefail

RAW="${PK95_RAW:-https://raw.githubusercontent.com/PhilippKuhlmann/pk95core/main}"
SWAP="${SWAP:-512}"
TEMPLATE_STORAGE="${TEMPLATE_STORAGE:-local}"

blue() { printf '\033[1;34m==> %s\033[0m\n' "$*"; }
bold() { printf '\033[1m%s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFehler: %s\033[0m\n' "$*" >&2; exit 1; }

# --- checks --------------------------------------------------------------------------------
[[ $(id -u) -eq 0 ]] || fail "Bitte als root auf dem Proxmox-Host ausführen."
command -v pct >/dev/null && command -v pveam >/dev/null && command -v pvesh >/dev/null \
  || fail "pct/pveam/pvesh fehlen – das Skript läuft nur auf einem Proxmox-VE-Host."

# The container needs the Core image without a login (ghcr.io, public package). Check before
# anything is created, otherwise the installation would stop half way.
IMAGE_REPO="${PK95_IMAGE_REPO:-philippkuhlmann/pk95core}"
# No -f: for a private image the token endpoint itself answers with an error.
ghcr_token="$(curl -s "https://ghcr.io/token?scope=repository:${IMAGE_REPO}:pull" 2>/dev/null | sed -n 's/.*"token":"\([^"]*\)".*/\1/p' || true)"
ghcr_code="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer ${ghcr_token}" \
  -H 'Accept: application/vnd.oci.image.index.v1+json,application/vnd.docker.distribution.manifest.v2+json' \
  "https://ghcr.io/v2/${IMAGE_REPO}/manifests/latest" || true)"
[[ "$ghcr_code" == 200 ]] || fail "Das Image ghcr.io/${IMAGE_REPO} lässt sich nicht ohne Anmeldung laden (HTTP ${ghcr_code}).
Auf GitHub unter Packages → ${IMAGE_REPO#*/} → Package settings → Change visibility auf „Public“ stellen. Es wurde nichts verändert."

# --- questions -----------------------------------------------------------------------------
# Questions go to the terminal (bash -c "$(curl …)" keeps it). Without one the defaults and the
# environment variables apply, so the script also runs unattended.
TTY=0
{ exec 3</dev/tty; } 2>/dev/null && TTY=1

# ask VAR "question" default check "hint": ask until check accepts the answer (Enter = default).
ask() {
  local var=$1 question=$2 default=$3 check=$4 hint=$5 value
  while :; do
    value=""
    if (( TTY )); then
      read -r -u 3 -p "  ${question} [${default:-leer}]: " value || value=""
    fi
    value="${value:-$default}"
    if "$check" "$value"; then
      printf -v "$var" '%s' "$value"
      return
    fi
    (( TTY )) || fail "${question}: ${hint} (${value:-leer})"
    printf '    \033[33m%s\033[0m\n' "$hint"
  done
}

# choose VAR "question" default item…: numbered list, answer with number or name.
choose() {
  local var=$1 question=$2 default=$3; shift 3
  local items=("$@") i value
  (( ${#items[@]} )) || fail "${question}: nichts zur Auswahl gefunden."
  if (( TTY )); then
    for i in "${!items[@]}"; do printf '    %d) %s\n' $((i + 1)) "${items[$i]}"; done
  fi
  while :; do
    value=""
    (( TTY )) && { read -r -u 3 -p "  ${question} [${default}]: " value || value=""; }
    value="${value:-$default}"
    [[ "$value" =~ ^[0-9]+$ ]] && (( value >= 1 && value <= ${#items[@]} )) && value="${items[$((value - 1))]}"
    for i in "${items[@]}"; do
      [[ "$i" == "$value" ]] && { printf -v "$var" '%s' "$value"; return; }
    done
    (( TTY )) || fail "${question}: ${value} gibt es nicht."
    printf '    \033[33mBitte eine Nummer oder einen Namen aus der Liste.\033[0m\n'
  done
}

id_free() {
  [[ "$1" =~ ^[0-9]+$ ]] && (( $1 >= 100 && $1 <= 999999999 )) || return 1
  ! pct status "$1" >/dev/null 2>&1 && ! { command -v qm >/dev/null && qm status "$1" >/dev/null 2>&1; }
}
hostname_ok() { [[ "$1" =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$ ]]; }
cores_ok() { [[ "$1" =~ ^[0-9]+$ ]] && (( $1 >= 1 && $1 <= 64 )); }
memory_ok() { [[ "$1" =~ ^[0-9]+$ ]] && (( $1 >= 1024 )); }
disk_ok() { [[ "$1" =~ ^[0-9]+$ ]] && (( $1 >= 8 )); }
vlan_ok() { [[ -z "$1" ]] || { [[ "$1" =~ ^[0-9]+$ ]] && (( $1 >= 1 && $1 <= 4094 )); }; }
ipv4() { [[ "$1" =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]] \
  && (( BASH_REMATCH[1] <= 255 && BASH_REMATCH[2] <= 255 && BASH_REMATCH[3] <= 255 && BASH_REMATCH[4] <= 255 )); }
ip_ok() { [[ "$1" == dhcp ]] || { [[ "$1" == */* ]] && ipv4 "${1%/*}" && [[ "${1#*/}" =~ ^[0-9]+$ ]] && (( ${1#*/} >= 8 && ${1#*/} <= 32 )); }; }

# Storages for container disks and bridges of this node, for the lists.
mapfile -t storages < <(pvesm status --content rootdir 2>/dev/null | awk 'NR>1 && $3=="active" {print $1}')
mapfile -t bridges < <(for b in /sys/class/net/*/bridge; do [[ -e "$b" ]] && basename "$(dirname "$b")"; done | grep '^vmbr' || true)
(( ${#bridges[@]} )) || bridges=(vmbr0)
default_storage="${STORAGE:-}"
if [[ -z "$default_storage" ]]; then
  for s in local-lvm local-zfs; do printf '%s\n' "${storages[@]}" | grep -qx "$s" && default_storage="$s" && break; done
  default_storage="${default_storage:-${storages[0]:-}}"
fi

echo
bold "PK95 Core – Installation auf Proxmox VE"
(( TTY )) && echo "  Enter übernimmt den Vorschlag in Klammern."
echo
bold "1. Container"
ask CTID "CT-ID" "${CTID:-$(pvesh get /cluster/nextid)}" id_free "Eine freie ID ab 100 (diese ist vergeben oder ungültig)."
ask CT_HOSTNAME "Hostname" "${CT_HOSTNAME:-pk95core}" hostname_ok "Kleinbuchstaben, Ziffern und Bindestriche."
echo
bold "2. Ressourcen"
ask CORES "CPU-Kerne" "${CORES:-2}" cores_ok "Eine Zahl von 1 bis 64."
ask MEMORY "RAM in MB" "${MEMORY:-2048}" memory_ok "Mindestens 1024 MB."
ask DISK "Disk in GB" "${DISK:-16}" disk_ok "Mindestens 8 GB."
(( TTY )) && echo "  Speicher für die Disk:"
choose STORAGE "Speicher" "$default_storage" "${storages[@]}"
echo
bold "3. Netzwerk"
(( TTY )) && echo "  Bridge:"
choose BRIDGE "Bridge" "${BRIDGE:-${bridges[0]}}" "${bridges[@]}"
ask VLAN "VLAN-Tag (leer = keins)" "${VLAN:-}" vlan_ok "Leer lassen oder eine Zahl von 1 bis 4094."
ask IP "IP-Adresse mit Präfix oder dhcp" "${IP:-dhcp}" ip_ok "dhcp oder eine Adresse wie 10.10.250.50/24."
GW="${GW:-}"
if [[ "$IP" != dhcp ]]; then
  ask GW "Gateway" "${GW:-${IP%.*}.1}" ipv4 "Eine Adresse wie 10.10.250.1."
else
  GW=""
fi

echo
bold "Zusammenfassung"
echo "  CT-ID ${CTID} · Hostname ${CT_HOSTNAME}"
echo "  ${CORES} Kerne · ${MEMORY} MB RAM · ${DISK} GB auf ${STORAGE}"
echo "  Netz ${BRIDGE}${VLAN:+ · VLAN ${VLAN}} · IP ${IP}${GW:+ · Gateway ${GW}}"
if (( TTY )); then
  read -r -u 3 -p "Jetzt installieren? [J/n] " answer || answer=j
  [[ "${answer:-j}" =~ ^[JjYy]$|^$ ]] || fail "Abgebrochen, es wurde nichts verändert."
  exec 3<&-
fi
echo
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
