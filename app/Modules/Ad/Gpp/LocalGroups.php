<?php

namespace App\Modules\Ad\Gpp;

/**
 * Local groups (PK95 AD 1.3.0): computer configuration → preferences → control panel settings
 * → local users and groups. Each row changes the members of one built-in local group:
 * domain users or groups to add and to remove, optionally removing all other members. The
 * action is always "update". With "administrators" the app keeps Domain Admins in the group.
 * The API field "group" (the local group) is "local_group" in the form: "group" there is the
 * security group of the condition.
 */
final class LocalGroups extends Kind
{
    public const GROUPS = [
        'administrators' => 'Administratoren',
        'remote_desktop_users' => 'Remotedesktopbenutzer',
        'users' => 'Benutzer',
        'remote_management_users' => 'Remoteverwaltungsbenutzer',
        'backup_operators' => 'Sicherungs-Operatoren',
        'event_log_readers' => 'Ereignisprotokollleser',
        'network_configuration_operators' => 'Netzwerkkonfigurations-Operatoren',
    ];

    public function key(): string
    {
        return 'local_groups';
    }

    public function endpoint(): string
    {
        return 'local-groups';
    }

    public function scope(): string
    {
        return 'computer';
    }

    public function title(): string
    {
        return 'Lokale Gruppen';
    }

    public function area(): string
    {
        return 'Lokale Benutzer und Gruppen';
    }

    public function count(int $n): string
    {
        return $n === 1 ? '1 Gruppe' : $n.' Gruppen';
    }

    public function listCount(int $n): string
    {
        return $n === 1 ? '1 lokale Gruppe' : $n.' lokale Gruppen';
    }

    public function since(): string
    {
        return '1.3.0';
    }

    public function max(): int
    {
        return count(self::GROUPS);
    }

    public function maxText(): string
    {
        return 'Jede lokale Gruppe kommt je Gruppenrichtlinie höchstens einmal vor.';
    }

    public function intro(): string
    {
        return 'Ändert auf den Computern die Mitglieder lokaler Gruppen, etwa wer Administrator ist oder sich per Remotedesktop anmelden darf.';
    }

    public function hint(): ?string
    {
        return 'Mitglieder sind Benutzer und Gruppen der Domäne; lokale Konten der Rechner lassen sich hier nicht wählen. Bei den Administratoren nimmt PK95 AD Domain Admins immer auf.';
    }

    public function searchWords(): string
    {
        return 'lokale gruppen benutzer administratoren remotedesktop mitglieder einstellungen';
    }

    public function addLabel(): string
    {
        return 'Lokale Gruppe hinzufügen';
    }

    public function emptyText(): string
    {
        return 'Noch keine lokalen Gruppen.';
    }

    public function newTitle(): string
    {
        return 'Neue lokale Gruppe';
    }

    public function rowLabel(array $row): string
    {
        return self::GROUPS[$row['local_group']] ?? $row['local_group'];
    }

    public function editorTitle(array $row): string
    {
        return 'Lokale Gruppe '.$this->rowLabel($row);
    }

    public function gets(): string
    {
        return 'bekommen diese Änderung';
    }

    public function columns(): array
    {
        return [
            ['Lokale Gruppe', ''],
            ['Mitglieder', ''],
            ['Gilt für', 'hidden sm:table-cell'],
        ];
    }

    /** the first local group the GPO does not change yet */
    public function blank(array $rows): array
    {
        $used = array_column($rows, 'local_group');
        foreach (array_keys(self::GROUPS) as $group) {
            if (! in_array($group, $used, true)) {
                return ['group' => $group];
            }
        }

        return ['group' => 'administrators'];
    }

    public function fieldsFromApi(array $item): array
    {
        $names = [];
        foreach (['add', 'remove'] as $list) {
            foreach ((array) ($item[$list] ?? []) as $member) {
                $names[(string) ($member['id'] ?? '')] = (string) ($member['name'] ?? '');
            }
        }

        return [
            'local_group' => (string) ($item['group'] ?? 'administrators'),
            'add_ids' => array_values(array_map(fn ($m) => (string) ($m['id'] ?? ''), (array) ($item['add'] ?? []))),
            'remove_ids' => array_values(array_map(fn ($m) => (string) ($m['id'] ?? ''), (array) ($item['remove'] ?? []))),
            'remove_others' => (bool) ($item['remove_others'] ?? false),
            // names of the members as the app knows them (the selection fields know the rest)
            'names' => $names,
        ];
    }

    public function fieldsToApi(array $row): array
    {
        return [
            'group' => (string) $row['local_group'],
            'add' => array_values(array_map(fn ($id) => ['id' => (string) $id], (array) $row['add_ids'])),
            'remove' => array_values(array_map(fn ($id) => ['id' => (string) $id], (array) $row['remove_ids'])),
            'remove_others' => (bool) $row['remove_others'],
        ];
    }

    public function searchText(array $row): string
    {
        return $this->rowLabel($row).' '.$row['local_group'].' '.implode(' ', (array) $row['names']);
    }

    /** "2.add.1.id" → "2.add_ids", "2.remove" → "2.remove_ids", "2.group" → "2.local_group" */
    public function errorField(string $rest): string
    {
        $rest = (string) preg_replace('/^(\d+)\.(add|remove)(\..*)?$/', '$1.$2_ids', $rest);
        $rest = (string) preg_replace('/^(\d+)\.group$/', '$1.local_group', $rest);

        return parent::errorField($rest);
    }
}
