# PK95 Core

Laravel 13 + Livewire 4 + Tailwind 4 (Vite). Central web UI that starts the PK95 apps as Docker containers
(Docker Engine API over the mounted socket) and renders their pages through Core modules.
Base code and DokuVault design taken from ProxApps (~/Proxmox/proxapps).

- Run tests: `php artisan test` (Docker and apps are faked via `tests/Concerns/FakesDocker.php`)
- Format: `vendor/bin/pint`
- Build assets: `npm run build`
- UI language: German. Code/comments: English.
- App contract: `docs/app-protocol.md`. Change it only together with the apps.
- New app: add `catalog/<id>.yaml`, a module in `app/Modules/<App>/` (extends `App\Modules\Module`) and
  register it in `config/pk95.php → modules`. Module pages are Livewire components with a public
  `InstalledApp $installedApp` and use `$installedApp->client()` for the app API.
- Never use `$component` as a view variable inside `<x-…>` slots (Blade reserves it).
