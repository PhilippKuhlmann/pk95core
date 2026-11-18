<?php

namespace App\Modules\Ad\Gpp;

/**
 * Shortcuts (PK95 AD 1.3.0): user configuration → preferences → Windows settings → shortcuts.
 * A link to a web address (http/https) or to a file or program on a share (UNC), placed on
 * the desktop or in the start menu.
 */
final class Shortcuts extends Kind
{
    public const LOCATIONS = ['desktop' => 'Desktop', 'start_menu' => 'Startmenü', 'programs' => 'Startmenü → Programme'];

    public const TARGET_TYPES = ['url' => 'Webadresse (URL)', 'file' => 'Datei oder Programm (UNC-Pfad)'];

    public function key(): string
    {
        return 'shortcuts';
    }

    public function endpoint(): string
    {
        return 'shortcuts';
    }

    public function scope(): string
    {
        return 'user';
    }

    public function title(): string
    {
        return 'Verknüpfungen';
    }

    public function area(): string
    {
        return 'Verknüpfungen';
    }

    public function count(int $n): string
    {
        return $n === 1 ? '1 Verknüpfung' : $n.' Verknüpfungen';
    }

    public function since(): string
    {
        return '1.3.0';
    }

    public function intro(): string
    {
        return 'Legt bei der Anmeldung Verknüpfungen auf dem Desktop oder im Startmenü an, etwa zum Intranet oder zu einem Programm auf einer Freigabe.';
    }

    public function searchWords(): string
    {
        return 'verknüpfungen verknüpfung desktop startmenü links einstellungen';
    }

    public function addLabel(): string
    {
        return 'Verknüpfung hinzufügen';
    }

    public function emptyText(): string
    {
        return 'Noch keine Verknüpfungen.';
    }

    public function newTitle(): string
    {
        return 'Neue Verknüpfung';
    }

    public function rowLabel(array $row): string
    {
        return $row['name'] !== '' ? $row['name'] : 'Verknüpfung';
    }

    public function editorTitle(array $row): string
    {
        return 'Verknüpfung '.$row['name'];
    }

    public function gets(): string
    {
        return 'bekommen diese Verknüpfung';
    }

    public function moveLabels(): array
    {
        return ['früher anlegen', 'später anlegen'];
    }

    public function actionHelp(): array
    {
        return [
            'update' => 'Legt die Verknüpfung an oder ändert sie (üblich).',
            'create' => 'Legt sie nur an, wenn es sie noch nicht gibt.',
            'replace' => 'Löscht sie und legt sie neu an.',
            'delete' => 'Löscht die Verknüpfung.',
        ];
    }

    public function columns(): array
    {
        return [
            ['Name', ''],
            ['Ziel', 'hidden md:table-cell'],
            ['Ort', 'hidden lg:table-cell'],
            ['Gilt für', 'hidden sm:table-cell'],
            ['Aktion', 'hidden md:table-cell'],
        ];
    }

    public function blank(array $rows): array
    {
        return ['target_type' => 'url', 'location' => 'desktop'];
    }

    public function fieldsFromApi(array $item): array
    {
        return [
            'name' => (string) ($item['name'] ?? ''),
            'target_type' => (string) ($item['target_type'] ?? 'url'),
            'target' => (string) ($item['target'] ?? ''),
            'location' => (string) ($item['location'] ?? 'desktop'),
            'arguments' => (string) ($item['arguments'] ?? ''),
            'icon_path' => (string) ($item['icon_path'] ?? ''),
            'icon_index' => (string) ($item['icon_index'] ?? 0),
            'action' => (string) ($item['action'] ?? 'update'),
        ];
    }

    public function fieldsToApi(array $row): array
    {
        $index = trim((string) $row['icon_index']);

        return [
            'name' => trim((string) $row['name']),
            'target_type' => (string) $row['target_type'],
            'target' => trim((string) $row['target']),
            'location' => (string) $row['location'],
            // arguments only for files and programs
            'arguments' => $row['target_type'] === 'file' ? trim((string) $row['arguments']) : '',
            'icon_path' => trim((string) $row['icon_path']),
            'icon_index' => preg_match('/^\d+$/', $index) ? (int) $index : ($index === '' ? 0 : $index),
            'action' => (string) $row['action'],
        ];
    }

    public function searchText(array $row): string
    {
        return $row['name'].' '.$row['target'].' '.(self::LOCATIONS[$row['location']] ?? '');
    }
}
