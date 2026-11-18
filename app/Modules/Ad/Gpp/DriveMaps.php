<?php

namespace App\Modules\Ad\Gpp;

/**
 * Drive maps (PK95 AD 1.1.0): user configuration → preferences → Windows settings → drive
 * maps, User\Preferences\Drives\Drives.xml. The condition is "target" here (the other
 * lists call it "audience").
 */
final class DriveMaps extends Kind
{
    public const VISIBILITY = ['unchanged' => 'Unverändert', 'hide' => 'Ausblenden', 'show' => 'Anzeigen'];

    public function key(): string
    {
        return 'drives';
    }

    public function endpoint(): string
    {
        return 'drives';
    }

    public function scope(): string
    {
        return 'user';
    }

    public function title(): string
    {
        return 'Netzlaufwerke';
    }

    public function area(): string
    {
        return 'Laufwerkszuordnungen';
    }

    public function count(int $n): string
    {
        return $n === 1 ? '1 Laufwerk' : $n.' Laufwerke';
    }

    public function listCount(int $n): string
    {
        return $n === 1 ? '1 Netzlaufwerk' : $n.' Netzlaufwerke';
    }

    public function since(): string
    {
        return '1.1.0';
    }

    public function audienceField(): string
    {
        return 'target';
    }

    public function intro(): string
    {
        return 'Verbindet bei der Anmeldung Laufwerke, von oben nach unten.';
    }

    public function hint(): ?string
    {
        return 'Die Freigaben liegen auf einem Fileserver oder NAS; die Benutzer brauchen dort Rechte mit ihrem Domänenkonto. Kennwörter für Laufwerke speichert PK95 AD bewusst nicht (unsicher, MS14-025).';
    }

    public function searchWords(): string
    {
        return 'netzlaufwerke laufwerkszuordnungen laufwerke einstellungen';
    }

    public function addLabel(): string
    {
        return 'Netzlaufwerk hinzufügen';
    }

    public function emptyText(): string
    {
        return 'Noch keine Netzlaufwerke.';
    }

    public function newTitle(): string
    {
        return 'Neues Netzlaufwerk';
    }

    public function rowLabel(array $row): string
    {
        return $row['letter'].':'.($row['path'] !== '' ? ' '.$row['path'] : '');
    }

    public function editorTitle(array $row): string
    {
        return 'Netzlaufwerk '.$row['letter'].':';
    }

    public function gets(): string
    {
        return 'bekommen dieses Laufwerk';
    }

    public function moveLabels(): array
    {
        return ['früher verbinden', 'später verbinden'];
    }

    public function actionHelp(): array
    {
        return [
            'update' => 'Verbindet das Laufwerk oder ändert eine bestehende Verbindung (üblich).',
            'create' => 'Verbindet nur, wenn der Buchstabe noch frei ist.',
            'replace' => 'Trennt das Laufwerk und verbindet es neu.',
            'delete' => 'Trennt das Laufwerk.',
        ];
    }

    public function columns(): array
    {
        return [
            ['Laufwerk', ''],
            ['Pfad', ''],
            ['Bezeichnung', 'hidden lg:table-cell'],
            ['Gilt für', 'hidden sm:table-cell'],
            ['Aktion', 'hidden md:table-cell'],
        ];
    }

    /** the first letter from H on (then D to G) no other fixed drive of this GPO uses */
    public function blank(array $rows): array
    {
        $used = array_column(array_filter($rows, fn ($d) => ! $d['first_available']), 'letter');
        foreach ([...range('H', 'Z'), ...range('D', 'G')] as $letter) {
            if (! in_array($letter, $used, true)) {
                return ['letter' => $letter];
            }
        }

        return ['letter' => 'Z'];
    }

    public function fieldsFromApi(array $item): array
    {
        return [
            'letter' => strtoupper((string) ($item['letter'] ?? 'H')),
            'first_available' => (bool) ($item['first_available'] ?? false),
            'path' => (string) ($item['path'] ?? ''),
            'label' => (string) ($item['label'] ?? ''),
            'action' => (string) ($item['action'] ?? 'update'),
            'reconnect' => (bool) ($item['reconnect'] ?? true),
            'visibility' => (string) ($item['visibility'] ?? 'unchanged'),
        ];
    }

    public function fieldsToApi(array $row): array
    {
        return [
            'letter' => strtoupper(trim((string) $row['letter'])),
            'first_available' => (bool) $row['first_available'],
            'path' => trim((string) $row['path']),
            'label' => trim((string) $row['label']),
            'action' => (string) $row['action'],
            'reconnect' => (bool) $row['reconnect'],
            'visibility' => (string) $row['visibility'],
        ];
    }

    public function searchText(array $row): string
    {
        return $row['letter'].': '.$row['path'].' '.$row['label'];
    }
}
