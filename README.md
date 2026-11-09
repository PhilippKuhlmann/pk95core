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
   └─ pk95-proxy      ← PK95 Proxy (host network, 80/443)
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
4. zeigt die Adresse und den **Einrichtungscode** an.

Danach `http://<IP>:8080` öffnen, den Code eingeben, das Admin-Konto anlegen und unter **Apps** PK95 Proxy, Uptime
und ProxApps aktivieren.

**Vorschläge vorbelegen** lassen sich mit Variablen vor dem Befehl. Ohne Terminal (z. B. aus einem anderen Skript)
läuft die Installation mit ihnen ganz ohne Fragen:

```bash
CTID=250 IP=10.10.250.50/24 GW=10.10.250.1 bash -c "$(curl -fsSL https://raw.githubusercontent.com/PhilippKuhlmann/pk95core/main/install/proxmox.sh)"
```

| Variable | Standard | Bedeutung |
|---|---|---|
| `CTID` | nächste freie ID | ID des Containers |
| `CT_HOSTNAME` | `pk95core` | Hostname |
| `CORES` / `MEMORY` / `DISK` | `2` / `2048` / `16` | Kerne, RAM in MB, Disk in GB |
| `STORAGE` | `local-lvm`, sonst `local-zfs`, sonst der erste passende | Speicher für die Disk |
| `TEMPLATE_STORAGE` | `local` | Speicher der Vorlagen |
| `BRIDGE` / `VLAN` | `vmbr0` / – | Netzwerk |
| `IP` / `GW` | `dhcp` / – | feste Adresse mit Präfix (`10.10.250.50/24`) und Gateway |

Läuft Core später unter einer Domain hinter einem Reverse Proxy, siehe
[docs/lxc-setup.md → Hinter einem Reverse Proxy](docs/lxc-setup.md#hinter-einem-reverse-proxy-https).

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

## Stand

- [x] Phase 1: Grundgerüst: Setup/Login, Katalog, Container-Lebenszyklus über die Docker API, dynamische Seitenleiste, Modul-Schnittstelle
- [x] Phase 2: PK95 Uptime anbinden (Core-Modus in der App, Modul `Uptime`)
- [x] Phase 3: Ereignisse und zentrale Benachrichtigungen (Telegram, E-Mail, ntfy, Webhook), Update-Prüfung
  gegen die Registry mit Regeln und Rückkehr bei Fehlern, Sicherungen der App-Volumes mit Wiederherstellung
- [x] Phase 4: ProxApps anbinden (alle Seiten über eine Komponenten-Brücke)
- [x] Phase 5: PK95 Proxy containerisieren und anbinden (Modul `Proxy`, Core-Modus im Panel)
- [x] Automatik: Mit ProxApps ausgerollte Apps bekommen einen Proxy-Host (aktiv, sobald DNS passt) und
  Monitore in PK95 Uptime; beim Entfernen räumt Core beides wieder ab
