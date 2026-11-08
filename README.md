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

- **Einrichtung im LXC:** [docs/lxc-setup.md](docs/lxc-setup.md)
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
- [ ] Phase 4: ProxApps anbinden
- [ ] Phase 5: PK95 Proxy containerisieren und anbinden
