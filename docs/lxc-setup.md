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
TRUSTED_PROXIES=192.168.1.5         # IP des Proxys (Beispiel), kommagetrennt auch mehrere
# SESSION_SECURE_COOKIE=true        # Login dann nur noch über HTTPS, nicht mehr über die LXC-IP
```

Ohne `TRUSTED_PROXIES` ignoriert Core `X-Forwarded-Proto` und erzeugt `http://`-Links: Der Browser
blockiert dann Stylesheet und Skripte als gemischte Inhalte.

## Datenablage

| Pfad im LXC | Inhalt |
|---|---|
| `/opt/pk95/core` | Core: SQLite-Datenbank, Anwendungsschlüssel (`app/private/app.key`). Ordner 0750, Dateien 0640, Schlüssel 0600 (setzt Core bei jedem Start) |
| `/srv/pk95/certs/<domain>/` | Let's-Encrypt-Zertifikate für andere Anwendungen (PK95 Proxy, Bind-Mount). Abholen per `scp` mit dem Benutzer `certsync` (nur SFTP, Gruppe `pk95-certs`, gid 2001 = Einstellung `proxy_certs_gid`) |
| `/srv/pk95/files/` | Freigaben von PK95 File (eigener Proxmox-Mountpoint, siehe [Dateiserver](#dateiserver-pk95-file)); nicht in den Sicherungen von Core |
| `/opt/pk95/backups/<app>/` | App-Sicherungen (`<Zeit>-<id>-<volume>.tar.gz`), erstellt von einem kurzlebigen `alpine`-Hilfscontainer. Enthalten die Geheimnisse der Apps: Ordner 0750, Archive 0640 |
| Docker-Volumes `pk95-<app>-<name>` | Daten der Apps (`docker volume ls --filter label=pk95.managed=1`) |

Ein Proxmox-Backup des LXC (vzdump/PBS) sichert alles zusammen. Den Anwendungsschlüssel nicht verlieren:
Mit ihm sind die Service-Tokens der Apps verschlüsselt.

## Zertifikate für andere Anwendungen (certsync)

PK95 Proxy legt die Let's-Encrypt-Zertifikate, die unter **Proxy → Zertifikate → Let's Encrypt für andere
Anwendungen** stehen, auf dem LXC unter `/srv/pk95/certs/<domain>/` ab (`fullchain.pem`, `privkey.pem`,
Gruppe `pk95-certs`, Dateien 0640). Andere Rechner holen sie mit dem Benutzer `certsync` per `scp` ab:

```bash
scp certsync@<LAN-IP>:/srv/pk95/certs/<domain>/fullchain.pem ./
scp certsync@<LAN-IP>:/srv/pk95/certs/<domain>/privkey.pem ./
```

Täglich per Cron, etwa um 04:15 nach `/etc/ssl/<domain>/`:

```
15 4 * * * scp -q certsync@<LAN-IP>:/srv/pk95/certs/<domain>/fullchain.pem certsync@<LAN-IP>:/srv/pk95/certs/<domain>/privkey.pem /etc/ssl/<domain>/
```

Alle Domains auf einmal, jede in einen eigenen Ordner `/etc/ssl/<domain>/`:

```
15 4 * * * scp -rq certsync@<LAN-IP>:/srv/pk95/certs/<domain1> certsync@<LAN-IP>:/srv/pk95/certs/<domain2> /etc/ssl/
```

`certsync` darf nur SFTP (lesend). `scp` ab OpenSSH 9 überträgt von selbst über SFTP, auch rekursiv mit `-r`; ältere
Versionen brauchen `scp -s`. Unter Proxy → Zertifikate stehen die Befehle fertig zum Kopieren, mit Auswahl der Domain.

**Was die Installation einrichtet** (`install/proxmox.sh` ruft dafür `install/certsync.sh` auf):

- Gruppe `pk95-certs` (gid 2001) und Benutzer `certsync` (uid 2001, ohne Passwort, Shell `nologin`).
  Die gid muss zur Einstellung **Gruppen-ID (gid) von pk95-certs** in Core passen (Standard 2001). Weicht sie ab,
  zeigt Core das unter Proxy → Zertifikate an.
- SSH für `certsync` (`/etc/ssh/sshd_config.d/pk95-certsync.conf`): nur Schlüssel, nur SFTP und nur lesend
  (`internal-sftp -R`), eingesperrt in `/srv/pk95`, ohne Weiterleitungen und ohne Terminal. Die Pfade
  `/srv/pk95/certs/…` gelten auch dort.
- Die Schlüssel stehen in `/opt/pk95/ssh/authorized_keys/certsync` (root, 0644).

**Schlüssel hinzufügen:** in Core unter **Proxy → Zertifikate → Zugang für certsync** den öffentlichen Schlüssel des
abholenden Rechners einfügen (etwa `~/.ssh/id_ed25519.pub`). Erlaubt sind `ssh-ed25519`, `ecdsa-sha2-*` und `ssh-rsa`
ab 3072 Bit, ohne Optionen wie `command=` oder `from=`. Dort lassen sie sich auch wieder entfernen.

**Bestehende Installationen** richten den Zugang einmal auf dem Proxmox-Host ein (`<CTID>` = Nummer des LXC). Das
Skript lässt sich gefahrlos wiederholen und übernimmt einen Schlüssel aus `/etc/ssh/authorized_keys/certsync`:

```bash
pct exec <CTID> -- bash -c "$(curl -fsSL https://raw.githubusercontent.com/PhilippKuhlmann/pk95core/main/install/certsync.sh)"
```
## Dateiserver (PK95 File)

PK95 File ist ein Samba-Dateiserver in der Domäne von PK95 AD (die App braucht PK95 AD im Zustand „eingerichtet“).
Er braucht zwei Dinge vom Proxmox-Host und aus dem LAN. Alle Werte hier sind Beispiele.

**Eigener Mountpoint für die Daten.** Die Freigaben liegen nicht auf der Systemplatte des LXC und nicht in den Sicherungen
von Core (Bind-Mount), sondern auf einem eigenen Proxmox-Mountpoint unter `/srv/pk95/files`, den das
Proxmox-Backup mitsichert (`backup=1`; pxar behält die NT-ACLs in den `user.*`-Attributen und die Hardlinks der
Vorgängerversionen):

```bash
pct set 950 -mp0 local-zfs:50,mp=/srv/pk95/files,backup=1
```

Vorgängerversionen brauchen grob das Doppelte der Daten: Auf 50 GB passen etwa 20 bis 25 GB Freigaben. Der
Mountpoint lässt sich später mit `pct resize 950 mp0 +50G` vergrößern. Ohne eigenen Mountpoint meldet der
Einrichtungsassistent „Die Daten lägen auf der Systemplatte des LXC“.

**Eigene IP-Adresse im LAN.** Der Dateiserver antwortet auf einer zweiten Adresse im Netz des LXC (z. B.
`192.168.1.20`, der LXC selbst hat etwa `192.168.1.10`). Sie muss **außerhalb des DHCP-Bereichs** des Routers
liegen, sonst vergibt der Router sie später an ein anderes Gerät. Die App legt sie beim Start selbst auf `eth0` und
prüft vorher per ARP, dass sie frei ist.

- Ist am LXC in Proxmox die **Firewall mit IP-Filter** (`ipfilter=1`) aktiv, lässt Proxmox nur die eingetragenen
  Adressen durch: die zusätzliche Adresse in Proxmox unter *Firewall → IPSet `ipfilter-net0`* eintragen.
- Vorschlag im Assistenten: Steht in der `.env` neben `docker-compose.yml` die Adresse, schlägt der Assistent sie vor
  (danach `docker compose up -d`). Ohne den Eintrag sucht die App eine freie Adresse direkt nach der des LXC.

```bash
PK95FILE_DEFAULT_IP=192.168.1.20    # optional
```

Danach PK95 File unter **Apps** aktivieren und unter *PK95 File → Dateiserver* einrichten: Core legt in PK95 AD das
Computerkonto und den DNS-Eintrag `files.<Domäne>` an und lässt den Server beitreten, ohne Administrator-Kennwort.

## Sicherheit

- Core hat über den Docker-Socket root-gleiche Rechte im LXC. Port 8080 nur im LAN freigeben.
  Sobald PK95 Proxy läuft, Core nur noch über HTTPS erreichbar machen (`SESSION_SECURE_COOKIE=true`).
- Die Apps veröffentlichen keine Ports. Sie sind nur im Docker-Netz `pk95` erreichbar, außer ihr Katalog-Eintrag sagt etwas anderes.

## Updates von Core und den Apps

Unter **Apps** steht Core als erste Karte, darunter die Apps. Jede Karte zeigt bei einem Update
„installiert 1.0.0 → neu 1.1.0“ und den Knopf **Aktualisieren**. Core prüft alle 6 Stunden, „Nach Updates
suchen“ oben auf der Seite sofort. **Alle aktualisieren** installiert alle Updates nacheinander: ProxApps,
Uptime, Proxy, DNS, zuletzt Core selbst, sobald kein App-Update mehr läuft. Am Ende steht eine Zusammenfassung
unter Ereignisse und auf der Seite.

Versionen: Die Images heißen `latest` nur für Releases (Git-Tag `v1.2.3`), Builds von `main` heißen `main`.
Beim Installieren und Aktualisieren setzt Core `latest` auf die konkrete Version (z. B. `1.2.3`), danach gelten
die Update-Regeln (nur Fehlerbehebungen, neue Funktionen, alle). Gibt es noch kein Release, bleibt die App auf
`latest` und Core zeigt neue Builds als „Build abc1234 vom …“. Braucht das Core-Modul eine andere Version
einer App, steht das als Hinweis auf ihrer Karte; automatische Updates auf eine unpassende Version
unterbleiben.

Beim Aktualisieren startet Core einen kurzlebigen Hilfscontainer `pk95core-updater` (`docker:28-cli`). Er führt
für das Compose-Projekt, aus dem Core gestartet wurde, `docker compose pull` und `up -d` aus. Danach wartet er
bis zu 3 Minuten auf `/up` der neuen Version und startet sonst wieder das vorige Image. Das Ergebnis erscheint
unter Ereignisse und auf der Karte. Voraussetzung: Core wurde mit `docker compose` gestartet, wie bei der
Installation.

Von Hand im LXC:

```bash
cd /opt/pk95 && docker compose pull && docker compose up -d
```
