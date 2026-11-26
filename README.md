# PK95 Core

Zentrale Weboberfläche für alle PK95-Apps im DokuVault-Design. Core läuft als Docker-Container in einem
Proxmox-LXC. Apps aus dem Katalog lassen sich per Klick aktivieren: Core startet sie als eigene Container
im selben LXC, spricht über eine API mit ihnen und blendet ihre Menüpunkte in der Seitenleiste ein.

```
Proxmox-Host
└─ LXC pk95core (Docker)
   ├─ pk95core        ← diese App (Port 8080), steuert Docker über den Socket
   ├─ pk95-uptime     ← PK95 Uptime
   ├─ pk95-proxapps   ← ProxApps
   ├─ pk95-proxy      ← PK95 Proxy (host network, 80/443)
   └─ pk95-dns        ← PK95 DNS (host network, 53)
```

## Installation auf Proxmox (ein Befehl)

Auf dem **Proxmox-Host** als `root` in der Shell (Weboberfläche → Node → „Shell“) einfügen:

```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/PhilippKuhlmann/pk95core/main/install/proxmox.sh)"
```

Das Skript fragt Schritt für Schritt, Enter übernimmt jeweils den Vorschlag in Klammern:

```
1. Container     CT-ID [nächste freie] · Hostname [pk95core]
2. Ressourcen    CPU-Kerne [2] · RAM in MB [2048] · Disk in GB [16] · Speicher (Auswahlliste)
3. Netzwerk      Bridge (Auswahlliste) · VLAN-Tag [leer] · IP mit Präfix oder dhcp [dhcp] · Gateway
                 Zusammenfassung → Jetzt installieren? [J/n]
```

Ungültige Eingaben (vergebene ID, Hostname mit Großbuchstaben, zu wenig RAM …) werden sofort angemerkt
und neu abgefragt. Danach erledigt das Skript alles allein:

1. lädt bei Bedarf die Debian-13-Vorlage,
2. legt einen unprivilegierten LXC mit Nesting und Keyctl für Docker an, der mit dem Host startet,
3. installiert darin Docker und startet PK95 Core,
4. richtet den SFTP-Zugang `certsync` für die Zertifikate anderer Anwendungen ein (siehe unten),
5. zeigt die Adresse und den **Einrichtungscode** an.

Danach `http://<IP>:8080` öffnen, den Code eingeben, das Admin-Konto anlegen und unter **Apps** PK95 Proxy, Uptime,
DNS und ProxApps aktivieren.

**Vorschläge vorbelegen** lassen sich mit Variablen vor dem Befehl. Ohne Terminal (z. B. aus einem anderen Skript)
läuft die Installation mit ihnen ganz ohne Fragen:

```bash
CTID=250 IP=192.168.1.50/24 GW=192.168.1.1 bash -c "$(curl -fsSL https://raw.githubusercontent.com/PhilippKuhlmann/pk95core/main/install/proxmox.sh)"
```

| Variable | Standard | Bedeutung |
|---|---|---|
| `CTID` | nächste freie ID | ID des Containers |
| `CT_HOSTNAME` | `pk95core` | Hostname |
| `CORES` / `MEMORY` / `DISK` | `2` / `2048` / `16` | Kerne, RAM in MB, Disk in GB |
| `STORAGE` | `local-lvm`, sonst `local-zfs`, sonst der erste passende | Speicher für die Disk |
| `TEMPLATE_STORAGE` | `local` | Speicher der Vorlagen |
| `BRIDGE` / `VLAN` | `vmbr0` / – | Netzwerk |
| `IP` / `GW` | `dhcp` / – | feste Adresse mit Präfix (`192.168.1.50/24`) und Gateway |

Läuft Core später unter einer Domain hinter einem Reverse Proxy, siehe
[docs/lxc-setup.md → Hinter einem Reverse Proxy](docs/lxc-setup.md#hinter-einem-reverse-proxy-https).

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
## Dokumentation

- **Einrichtung von Hand:** [docs/lxc-setup.md](docs/lxc-setup.md)
- **Vertrag für Apps:** [docs/app-protocol.md](docs/app-protocol.md)
- **Katalog:** `catalog/<id>.yaml`
- **Module (Seiten einer App in Core):** `app/Modules/<App>/`, eingetragen in `config/pk95.php`

## Entwicklung

```bash
composer install && npm ci && npm run build
cp .env.example .env && php artisan key:generate && touch database/database.sqlite && php artisan migrate
php artisan serve
php artisan test
```

Ohne Docker-Socket läuft die Oberfläche trotzdem. Sie meldet dann „Docker ist nicht erreichbar“.

**Release:** Ein Git-Tag `v1.2.3` baut das Image als `1.2.3`, `1.2` und `latest`, mit der Version im Image
(`PK95_VERSION`). Pushes auf `main` bauen nur `main` und `sha-…`, laufende Installationen bekommen davon nichts.

```bash
git tag v1.2.3 && git push origin v1.2.3
```

## Stand

- [x] Phase 1: Grundgerüst: Setup/Login, Katalog, Container-Lebenszyklus über die Docker API, dynamische Seitenleiste, Modul-Schnittstelle
- [x] Phase 2: PK95 Uptime anbinden (Core-Modus in der App, Modul `Uptime`)
- [x] Phase 3: Ereignisse und zentrale Benachrichtigungen (Telegram, E-Mail, ntfy, Webhook), Update-Prüfung
  gegen die Registry mit Regeln und Rückkehr bei Fehlern, Sicherungen der App-Volumes mit Wiederherstellung
- [x] Phase 4: ProxApps anbinden (alle Seiten über eine Komponenten-Brücke)
- [x] Phase 5: PK95 Proxy containerisieren und anbinden (Modul `Proxy`, Core-Modus im Panel)
- [x] Automatik: Mit ProxApps ausgerollte Apps bekommen einen Proxy-Host (aktiv, sobald DNS passt), einen
  DNS-Eintrag in PK95 DNS (A/AAAA: gleiche Domain → LAN-IP des Proxy, dort als „von Core“ markiert) und
  Monitore in PK95 Uptime; beim Entfernen
  räumt Core alles wieder ab
- [x] Phase 6: PK95 DNS anbinden (DNS-Filter wie AdGuard Home / Pi-hole, Repo pk95dns, Modul `Dns`;
  verschlüsselte Upstreams über DoH, DoH3, DoQ und DoT)
- [x] PK95 DNS als vollwertiger DNS-Server: eigene Zonen und Einträge (A, AAAA, CNAME, MX, TXT, SRV, PTR) unter
  „DNS-Einträge“; das Modul braucht PK95 DNS ab 1.1.0
- [x] Core aktualisiert sich selbst: unter Apps → PK95 Core, mit Rückkehr zur vorigen Version, wenn die neue
  nicht startet
