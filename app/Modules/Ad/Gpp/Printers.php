<?php

namespace App\Modules\Ad\Gpp;

/**
 * Printers (PK95 AD 1.3.0): user configuration → preferences → control panel settings →
 * printers. Only shared printers of a print server (\\server\printer), no TCP/IP printers.
 */
final class Printers extends Kind
{
    public function key(): string
    {
        return 'printers';
    }

    public function endpoint(): string
    {
        return 'printers';
    }

    public function scope(): string
    {
        return 'user';
    }

    public function title(): string
    {
        return 'Drucker';
    }

    public function area(): string
    {
        return 'Drucker';
    }

    public function count(int $n): string
    {
        return $n.' Drucker';
    }

    public function since(): string
    {
        return '1.3.0';
    }

    public function intro(): string
    {
        return 'Verbindet bei der Anmeldung freigegebene Drucker, von oben nach unten.';
    }

    public function hint(): ?string
    {
        return 'Nur freigegebene Drucker eines Druckservers (\\\\server\\drucker), keine TCP/IP-Drucker. Seit den PrintNightmare-Updates installieren normale Benutzer keine Treiber vom Druckserver: Der Druckertreiber muss auf dem Rechner vorinstalliert sein.';
    }

    public function searchWords(): string
    {
        return 'drucker druckserver standarddrucker einstellungen';
    }

    public function addLabel(): string
    {
        return 'Drucker hinzufügen';
    }

    public function emptyText(): string
    {
        return 'Noch keine Drucker.';
    }

    public function newTitle(): string
    {
        return 'Neuer Drucker';
    }

    public function rowLabel(array $row): string
    {
        return $row['path'] !== '' ? $row['path'] : 'Drucker';
    }

    public function editorTitle(array $row): string
    {
        return 'Drucker '.$row['path'];
    }

    public function gets(): string
    {
        return 'bekommen diesen Drucker';
    }

    public function moveLabels(): array
    {
        return ['früher verbinden', 'später verbinden'];
    }

    public function actionHelp(): array
    {
        return [
            'update' => 'Verbindet den Drucker oder ändert eine bestehende Verbindung (üblich).',
            'create' => 'Verbindet nur, wenn der Drucker noch nicht verbunden ist.',
            'replace' => 'Trennt den Drucker und verbindet ihn neu.',
            'delete' => 'Trennt den Drucker.',
        ];
    }

    public function columns(): array
    {
        return [
            ['Drucker', ''],
            ['Gilt für', 'hidden sm:table-cell'],
            ['Aktion', 'hidden md:table-cell'],
        ];
    }

    public function blank(array $rows): array
    {
        return [];
    }

    public function fieldsFromApi(array $item): array
    {
        return [
            'path' => (string) ($item['path'] ?? ''),
            'action' => (string) ($item['action'] ?? 'update'),
            'default' => (bool) ($item['default'] ?? false),
        ];
    }

    public function fieldsToApi(array $row): array
    {
        return [
            'path' => trim((string) $row['path']),
            'action' => (string) $row['action'],
            'default' => (bool) $row['default'],
        ];
    }

    public function searchText(array $row): string
    {
        return $row['path'].($row['default'] ? ' standarddrucker' : '');
    }
}
