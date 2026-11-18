<?php

namespace App\Modules\Ad\Gpp;

/**
 * Local groups (PK95 AD 1.3.0): computer configuration → preferences → control panel settings
 * → local users and groups, Machine\Preferences\Groups\Groups.xml. Each row changes the members
 * of one built-in local group: domain users or security groups to add and to remove (the API
 * list "members" with action add | remove; the form keeps two lists), optionally removing every
 * other member user and/or group (GPMC "Delete all member users / groups"). The action is
 * always "update". With "administrators" and a switch on the app adds Domain Admins itself.
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

    /** members per row */
    public const MEMBERS_MAX = 100;

    public function check(array $row): array
    {
        $errors = [];
        if (array_intersect((array) $row['add_ids'], (array) $row['remove_ids'])) {
            $errors['members'] = 'Ein Konto kann nicht zugleich hinzugefügt und entfernt werden.';
        } elseif (count((array) $row['add_ids']) + count((array) $row['remove_ids']) > self::MEMBERS_MAX) {
            $errors['members'] = 'Höchstens '.self::MEMBERS_MAX.' Mitglieder je lokaler Gruppe.';
        }

        return $errors;
    }

    public function intro(): string
    {
        return 'Ändert auf den Computern die Mitglieder lokaler Gruppen, etwa wer Administrator ist oder sich per Remotedesktop anmelden darf.';
    }

    public function hint(): ?string
    {
        return 'Mitglieder sind Benutzer und Sicherheitsgruppen der Domäne; lokale Konten der Rechner lassen sich hier nicht wählen. Entfernt eine Zeile bei den Administratoren alle anderen Mitglieder, nimmt PK95 AD Domain Admins immer auf.';
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
            ['Mitglieder', 'hidden sm:table-cell'],
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
        $add = [];
        $remove = [];
        $names = [];
        foreach ((array) ($item['members'] ?? []) as $member) {
            $id = (string) ($member['id'] ?? '');
            if (($member['action'] ?? 'add') === 'remove') {
                $remove[] = $id;
            } else {
                $add[] = $id;
            }
            $names[$id] = (string) ($member['name'] ?? '');
        }

        return [
            'local_group' => (string) ($item['group'] ?? 'administrators'),
            'add_ids' => $add,
            'remove_ids' => $remove,
            'remove_other_users' => (bool) ($item['remove_other_users'] ?? false),
            'remove_other_groups' => (bool) ($item['remove_other_groups'] ?? false),
            // names of the members as the app knows them (the selection fields know the rest)
            'names' => $names,
        ];
    }

    public function fieldsToApi(array $row): array
    {
        $members = [];
        foreach (['add' => 'add_ids', 'remove' => 'remove_ids'] as $action => $field) {
            foreach ((array) $row[$field] as $id) {
                $members[] = ['id' => (string) $id, 'action' => $action];
            }
        }

        return [
            'group' => (string) $row['local_group'],
            'members' => $members,
            'remove_other_users' => (bool) $row['remove_other_users'],
            'remove_other_groups' => (bool) $row['remove_other_groups'],
        ];
    }

    /** what the switches remove: "" | "Benutzer" | "Gruppen" | "Benutzer und Gruppen" */
    public static function removesOthers(array $row): string
    {
        return match (true) {
            $row['remove_other_users'] && $row['remove_other_groups'] => 'Benutzer und Gruppen',
            $row['remove_other_users'] => 'Benutzer',
            $row['remove_other_groups'] => 'Gruppen',
            default => '',
        };
    }

    public function searchText(array $row): string
    {
        return $this->rowLabel($row).' '.$row['local_group'].' '.implode(' ', (array) $row['names']);
    }

    /** "2.members.1.id" → "2.members" (shown under both selections), "2.group" → "2.local_group" */
    public function errorField(string $rest): string
    {
        $rest = (string) preg_replace('/^(\d+)\.members(\..*)?$/', '$1.members', $rest);
        $rest = (string) preg_replace('/^(\d+)\.group$/', '$1.local_group', $rest);

        return parent::errorField($rest);
    }
}
