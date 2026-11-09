# PK95 Core im Proxmox-LXC einrichten

## 1. LXC anlegen (auf dem Proxmox-Host)

Debian 13, unprivileged, mit Nesting (für Docker) und keyctl. VMID, Template-Name und Speicher an die eigene Umgebung anpassen:

```bash
pct create 950 local:vztmpl/debian-13-standard_13.1-2_amd64.tar.zst \
  --hostname pk95core --cores 2 --memory 2048 --swap 512 \
  --rootfs local-lvm:16 --net0 name=eth0,bridge=vmbr0,ip=dhcp \
  --unprivileged 1 --features nesting=1,keyctl=1 --onboot 1
pct start 950
```

> Später läuft PK95 Proxy mit `network_mode: host` und eigener Firewall (nftables) in diesem LXC.
> Ob das unprivileged klappt, prüfen wir am Anfang von Phase 5. Notfalls wird der LXC privileged.

## 2. Docker installieren (im LXC)

```bash
apt update && apt install -y ca-certificates curl
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
echo "deb [signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $(. /etc/os-release && echo $VERSION_CODENAME) stable" \
  > /etc/apt/sources.list.d/docker.list
apt update && apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
```

## 3. Core starten

`docker-compose.yml` aus diesem Repository nach `/opt/pk95/` kopieren, dann:

```bash
mkdir -p /opt/pk95/core /opt/pk95/backups
cd /opt/pk95 && docker compose up -d
docker logs pk95core | grep Einrichtungscode
```

Danach `http://<LXC-IP>:8080` öffnen, den Einrichtungscode eingeben und das Admin-Konto anlegen.

### Hinter einem Reverse Proxy (HTTPS)

Läuft Core unter einer Domain hinter einem Proxy, kommen Adresse und Proxy in eine `.env` neben
`docker-compose.yml`, danach `docker compose up -d`:

```bash
APP_URL=https://core.example.de
TRUSTED_PROXIES=10.10.250.95        # IP des Proxys, kommagetrennt auch mehrere
# SESSION_SECURE_COOKIE=true        # Login dann nur noch über HTTPS, nicht mehr über die LXC-IP
```

Ohne `TRUSTED_PROXIES` ignoriert Core `X-Forwarded-Proto` und erzeugt `http://`-Links: Der Browser
blockiert dann Stylesheet und Skripte als gemischte Inhalte.

## Datenablage

| Pfad im LXC | Inhalt |
|---|---|
| `/opt/pk95/core` | Core: SQLite-Datenbank, Anwendungsschlüssel (`app/private/app.key`) |
| `/opt/pk95/backups/<app>/` | App-Sicherungen (`<Zeit>-<id>-<volume>.tar.gz`), erstellt von einem kurzlebigen `alpine`-Hilfscontainer |
| Docker-Volumes `pk95-<app>-<name>` | Daten der Apps (`docker volume ls --filter label=pk95.managed=1`) |

Ein Proxmox-Backup des LXC (vzdump/PBS) sichert alles zusammen. Den Anwendungsschlüssel nicht verlieren:
Mit ihm sind die Service-Tokens der Apps verschlüsselt.

## Sicherheit

- Core hat über den Docker-Socket root-gleiche Rechte im LXC. Port 8080 nur im LAN freigeben.
  Sobald PK95 Proxy läuft, Core nur noch über HTTPS erreichbar machen (`SESSION_SECURE_COOKIE=true`).
- Die Apps veröffentlichen keine Ports. Sie sind nur im Docker-Netz `pk95` erreichbar, außer ihr Katalog-Eintrag sagt etwas anderes.

## Updates von Core

Unter **Apps** steht Core als erste Karte, mit Build, Datum und dem Knopf **Aktualisieren**, sobald die
Registry ein neueres Image hat. Core prüft das alle 6 Stunden, „Nach Updates suchen“ sofort.

Beim Aktualisieren startet Core einen kurzlebigen Hilfscontainer `pk95core-updater` (`docker:28-cli`). Er führt
für das Compose-Projekt, aus dem Core gestartet wurde, `docker compose pull` und `up -d` aus. Danach wartet er
bis zu 3 Minuten auf `/up` der neuen Version und startet sonst wieder das vorige Image. Das Ergebnis erscheint
unter Ereignisse und auf der Karte. Voraussetzung: Core wurde mit `docker compose` gestartet, wie bei der
Installation.

Von Hand im LXC:

```bash
cd /opt/pk95 && docker compose pull && docker compose up -d
```
