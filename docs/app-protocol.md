# PK95 App-Protokoll v1

Vertrag zwischen PK95 Core und den Apps (PK95 Uptime, ProxApps, PK95 Proxy, …).
Eine App, die ihn erfüllt, kann Core als Container starten und in seiner Oberfläche verwalten.

## 1. Core-Modus

Core startet jede App mit diesen Umgebungsvariablen:

| Variable | Inhalt |
|---|---|
| `PK95_CORE_MODE` | `1`: die App läuft unter Core |
| `PK95_CORE_URL` | Adresse von Core im Docker-Netz, z. B. `http://pk95core:8080` |
| `PK95_CORE_TOKEN` | zufälliger Service-Token dieser App (48 Zeichen). Er wird bei jeder (Neu-)Aktivierung neu erzeugt. |
| `TZ` | Zeitzone von Core |

Im Core-Modus gilt für die App:

- **Eigene Admin-Oberfläche aus.** Öffentliche Teile (z. B. Statusseiten) bleiben erreichbar.
- **Eigener Login aus.** Alle Admin-API-Aufrufe kommen von Core mit `Authorization: Bearer <PK95_CORE_TOKEN>`.
  Die App vergleicht den Token in konstanter Zeit (`timingSafeEqual`, `hash_equals`, `subtle.ConstantTimeCompare`).
  Ohne gültigen Token antwortet sie mit `401`.
- **Eigene Benachrichtigungen aus.** Stattdessen schickt sie Ereignisse an Core (Abschnitt 4).
- **Eigene Self-Updates aus.** Core aktualisiert das Image.

Ohne `PK95_CORE_MODE` läuft die App wie bisher allein (eigene UI, eigener Login).

## 2. Endpunkte der App

Alle Pfade liegen auf dem Port aus dem Katalog (`port`). `/core/*` braucht den Bearer-Token, nur `/core/health` nicht.

### `GET /core/health`

`2xx`, sobald die App Anfragen bedienen kann (Datenbank offen, Migrationen fertig). Ohne Auth.
Core nutzt den Endpunkt nach dem Start, nach Updates und jede Minute zur Statusprüfung.

### `GET /core/manifest`

```json
{
  "id": "uptime",
  "name": "PK95 Uptime",
  "version": "1.4.0",
  "protocol": 1,
  "icon": "signal",
  "menu": [
    { "key": "monitors", "label": "Monitore", "icon": "signal" },
    { "key": "incidents", "label": "Vorfälle", "icon": "warn" },
    { "key": "status-pages", "label": "Statusseiten", "icon": "globe" }
  ],
  "capabilities": ["events", "backup"],
  "events": ["monitor.down", "monitor.up", "certificate.expiring"]
}
```

| Feld | Pflicht | Bedeutung |
|---|---|---|
| `id` | ja | muss der `id` im Core-Katalog entsprechen |
| `name`, `version` | ja | Anzeige. `version` ist SemVer und steuert, ob das Core-Modul diese Version versteht. |
| `protocol` | ja | Protokollversion, aktuell `1` |
| `icon` | nein | Icon-Name aus Core (`resources/views/components/icon.blade.php`) |
| `menu[]` | nein | Seiten, die die App in der Seitenleiste haben möchte. `key`: `a-z0-9-`. Core zeigt nur Einträge, für die sein Modul eine Seite hat. |
| `capabilities[]` | nein | `events`, `backup` (siehe unten) |
| `events[]` | nein | Ereignistypen, die die App senden kann (für Benachrichtigungsregeln) |

### `POST /core/backup/prepare` (nur mit Capability `backup`)

Die App bringt ihre Daten in einen konsistenten Zustand, z. B. mit einem SQLite-`wal_checkpoint(TRUNCATE)`.
Danach stoppt Core den Container, sichert die Volumes und startet ihn wieder. Antwort: `204`.

### Admin-API

Die fachliche API der App (z. B. `/api/monitors`) bleibt, wie sie ist. Im Core-Modus akzeptiert sie den Bearer-Token.
Das Core-Modul der App ruft sie über `$installedApp->client()` auf.

## 3. Lebenszyklus (wie Core eine App betreibt)

1. Image `image:tag` aus dem Katalog ziehen (GHCR, öffentlich)
2. Netz `pk95` und Volumes `pk95-<id>-<name>` anlegen
3. Container `pk95-<id>` mit den Variablen aus Abschnitt 1 erstellen und starten. Labels: `pk95.managed=1` und `pk95.app=<id>`. Restart-Policy: `unless-stopped`.
4. Auf `GET /core/health` warten (Zeitlimit 120 s)
5. `GET /core/manifest` lesen und prüfen (`id`, `protocol`)
6. Status „Läuft“. Die Menüpunkte erscheinen in der Seitenleiste.

Core erreicht die App unter `http://pk95-<id>:<port>`. Apps mit `network_mode: host` erreicht es über
`http://host.docker.internal:<port>`. Die App erreicht Core unter `PK95_CORE_URL`.

## 4. Ereignisse an Core

```http
POST {PK95_CORE_URL}/api/v1/events
Authorization: Bearer <PK95_CORE_TOKEN>
Content-Type: application/json

{
  "type": "monitor.down",
  "severity": "critical",
  "title": "example.org ist nicht erreichbar",
  "body": "HTTP 502 seit 10:41 Uhr",
  "data": { "monitor_id": 7 },
  "occurred_at": "2026-11-08T10:41:00Z"
}
```

`severity`: `info`, `warning` oder `critical`. `type`: `a-z0-9_.-`, höchstens 100 Zeichen. Core legt das Ereignis im
Log ab und verteilt es an die Kanäle, deren Filter passt (Telegram, E-Mail, ntfy, Webhook). Antwort: `202 {"id": …}`.
Fehler: `401` falscher Token, `422` ungültiges Ereignis (`{"error", "fields"}`), `429` mehr als 120 Ereignisse pro Minute.
Core selbst meldet unter der App-ID `core` bzw. der jeweiligen App: `core.app.unhealthy|failed|stopped|recovered`,
`core.update.available|done|failed`, `core.backup.failed|restore_failed`. Ist Core nicht erreichbar,
puffert die App das Ereignis kurz und versucht es erneut. Sie verwirft es spätestens nach einer Stunde.

## 5. Katalog-Eintrag in Core (`catalog/<id>.yaml`)

```yaml
id: uptime
name: PK95 Uptime
summary: Überwacht Dienste und Webseiten …
image: ghcr.io/philippkuhlmann/pk95-uptime
tag: latest
port: 3001
icon: signal
module: uptime          # Core-Modul (config/pk95.php → modules)
health: /core/health    # Standard
network_mode: bridge    # oder host (PK95 Proxy)
cap_add: []             # z. B. [NET_ADMIN, NET_RAW]
ports: []               # veröffentlichte Ports "host:container[/udp]" (nur bridge)
sysctls:                # nur net.*, nur bridge – z. B. Ping ohne NET_RAW:
  net.ipv4.ping_group_range: "0 65535"   # im unprivilegierten LXC höchstens 65535
volumes:
  - name: data
    target: /data
env:
  DATA_DIR: /data
```

## 6. Versionierung

- Inkompatible Änderungen am Protokoll erhöhen `protocol`. Core gibt in `config/pk95.php → protocols` an, welche Versionen es spricht.
- Jedes Core-Modul gibt die unterstützten App-Versionen an (`minVersion` einschließlich, `maxVersion` ausschließlich).
  Liegt eine App-Version außerhalb, bleibt die App sichtbar, aber ihre Seiten sind gesperrt, bis Core oder App aktualisiert sind.
