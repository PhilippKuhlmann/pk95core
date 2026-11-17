#!/usr/bin/env bash
# PK95 Core – SFTP access "certsync" for the Let's Encrypt certificates of PK95 Proxy, which
# lie on the LXC under /srv/pk95/certs (group pk95-certs). certsync may only read them with
# scp/sftp: no shell, no password, chroot /srv/pk95, keys from /opt/pk95/ssh/authorized_keys
# (managed in Core under Proxy → Zertifikate).
#
# Runs as root inside the LXC and can be repeated (install/proxmox.sh calls it). For an
# existing installation once, on the Proxmox host:
#   pct exec <CTID> -- bash -c "$(curl -fsSL https://raw.githubusercontent.com/PhilippKuhlmann/pk95core/main/install/certsync.sh)"
# or on the LXC:
#   curl -fsSL https://raw.githubusercontent.com/PhilippKuhlmann/pk95core/main/install/certsync.sh | bash
#
# The gid has to match the setting "Gruppen-ID (gid) von pk95-certs" in Core (default 2001).
# uid 2001 on purpose: the files belong to the user of the proxy container (999), certsync
# must not own them.
set -euo pipefail

CERTS_GID="${CERTS_GID:-2001}"
CERTSYNC_UID="${CERTSYNC_UID:-2001}"
ROOT=/srv/pk95
KEYS_DIR=/opt/pk95/ssh/authorized_keys
OLD_KEYS=/etc/ssh/authorized_keys/certsync
SSHD_CONF=/etc/ssh/sshd_config.d/pk95-certsync.conf

say() { printf '==> %s\n' "$*"; }
fail() { printf 'Fehler: %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" = 0 ] || fail "Bitte als root ausführen."

if ! command -v sshd >/dev/null 2>&1; then
    say "Installiere den SSH-Server"
    DEBIAN_FRONTEND=noninteractive apt-get install -y -q openssh-server >/dev/null
fi

# --- group and user ---------------------------------------------------------------------
if getent group pk95-certs >/dev/null; then
    have="$(getent group pk95-certs | cut -d: -f3)"
    if [ "$have" != "$CERTS_GID" ]; then
        echo "Hinweis: Die Gruppe pk95-certs hat die gid ${have}, nicht ${CERTS_GID}." >&2
        echo "         Stelle in PK95 Core unter Einstellungen die Gruppen-ID auf ${have} und erstelle PK95 Proxy neu." >&2
        CERTS_GID="$have"
    fi
else
    getent group "$CERTS_GID" >/dev/null && fail "gid ${CERTS_GID} ist schon vergeben ($(getent group "$CERTS_GID" | cut -d: -f1)). Mit CERTS_GID=<frei> erneut starten."
    say "Lege die Gruppe pk95-certs an (gid ${CERTS_GID})"
    groupadd --system --gid "$CERTS_GID" pk95-certs
fi

if id certsync >/dev/null 2>&1; then
    [ "$(id -g certsync)" = "$CERTS_GID" ] || usermod --gid pk95-certs certsync
else
    getent passwd "$CERTSYNC_UID" >/dev/null && fail "uid ${CERTSYNC_UID} ist schon vergeben ($(getent passwd "$CERTSYNC_UID" | cut -d: -f1)). Mit CERTSYNC_UID=<frei> erneut starten."
    say "Lege den Benutzer certsync an (uid ${CERTSYNC_UID}, nur SFTP)"
    useradd --system --no-create-home --home-dir / --shell /usr/sbin/nologin --gid pk95-certs --uid "$CERTSYNC_UID" certsync
fi
# no password at all: only keys
usermod -p '*' certsync

# --- chroot: /srv/pk95, where the host paths /srv/pk95/certs/… stay valid ------------------
# sshd wants the chroot and all folders above it owned by root and not writable for others.
mkdir -p "$ROOT/srv/pk95"
chown root:root /srv "$ROOT" "$ROOT/srv" "$ROOT/srv/pk95"
chmod 0755 "$ROOT" "$ROOT/srv" "$ROOT/srv/pk95"
chmod go-w /srv
[ -L "$ROOT/srv/pk95/certs" ] && [ "$(readlink "$ROOT/srv/pk95/certs")" = /certs ] || ln -sfn /certs "$ROOT/srv/pk95/certs"

# --- keys: written by Core (as root, through a helper container) ---------------------------
mkdir -p "$KEYS_DIR"
chown root:root /opt/pk95/ssh "$KEYS_DIR"
chmod 0755 /opt/pk95/ssh "$KEYS_DIR"
touch "$KEYS_DIR/certsync"
# a key set up by hand under the old path is taken over
if [ -s "$OLD_KEYS" ]; then
    while IFS= read -r line; do
        [ -z "$line" ] && continue
        grep -qxF -- "$line" "$KEYS_DIR/certsync" || printf '%s\n' "$line" >> "$KEYS_DIR/certsync"
    done < "$OLD_KEYS"
    say "Schlüssel aus ${OLD_KEYS} übernommen"
fi
chown root:root "$KEYS_DIR/certsync"
chmod 0644 "$KEYS_DIR/certsync"

# what Core shows and compares with its setting proxy_certs_gid
printf 'CERTS_GID=%s\nCERTSYNC_UID=%s\n' "$(getent group pk95-certs | cut -d: -f3)" "$(id -u certsync)" > /opt/pk95/ssh/certsync.env
chmod 0644 /opt/pk95/ssh/certsync.env

# --- sshd -------------------------------------------------------------------------------
# (read -d: no command substitution around the here-document, works with every bash)
read -r -d '' conf <<'EOF' || true
# PK95 Core: certsync may only read /srv/pk95 (the certificates of PK95 Proxy) with scp/sftp.
Match User certsync
  ChrootDirectory /srv/pk95
  ForceCommand internal-sftp -R
  AuthorizedKeysFile /opt/pk95/ssh/authorized_keys/%u
  AuthenticationMethods publickey
  PasswordAuthentication no
  AllowTcpForwarding no
  AllowAgentForwarding no
  AllowStreamLocalForwarding no
  PermitTunnel no
  X11Forwarding no
  PermitTTY no
Match all
EOF
conf="${conf%$'\n'}"
if [ ! -f "$SSHD_CONF" ] || [ "$(cat "$SSHD_CONF")" != "$conf" ]; then
    mkdir -p /etc/ssh/sshd_config.d
    printf '%s\n' "$conf" > "$SSHD_CONF"
    chmod 0644 "$SSHD_CONF"
    sshd -t || fail "Die SSH-Konfiguration ist ungültig (sshd -t), siehe oben."
    # Debian 13 starts sshd through ssh.socket: a reload may end it briefly, the socket
    # starts it again. Not an error.
    systemctl try-reload-or-restart ssh >/dev/null 2>&1 || true
    say "SSH-Konfiguration für certsync gesetzt"
fi

ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
say "certsync ist eingerichtet. Schlüssel fügst du in PK95 Core unter Proxy → Zertifikate hinzu."
echo "    Abholen: scp certsync@${ip:-<LAN-IP>}:/srv/pk95/certs/<domain>/fullchain.pem ./"
