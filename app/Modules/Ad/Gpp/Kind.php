<?php

namespace App\Modules\Ad\Gpp;

/**
 * One kind of Group Policy Preferences list of PK95 AD (drive maps, local groups, printers,
 * shortcuts): GET/PUT /api/gpos/{id}/<endpoint> with {items} in file order, at most max()
 * items. What all kinds share lives here and in Concerns\EditsPreferences and the partials
 * in views/modules/ad/partials/gpp: uid (left out for new items), read-only items sent back
 * as they came (leaving one out deletes it), the condition on a security group in "target"
 * (all | member | not_member ↔ {group_id, group_name, not} or null), changed_at, warning.
 * A kind only adds its own fields, columns and editor (views gpp/<key>/cells and fields).
 */
abstract class Kind
{
    /** drive maps, printers and shortcuts know these four actions, like the GPMC */
    public const ACTIONS = [
        'update' => 'Aktualisieren',
        'create' => 'Erstellen',
        'replace' => 'Ersetzen',
        'delete' => 'Löschen',
    ];

    /** key in Gpos::$gpp, in field names ("gpp.drives.2.path") and in the GPO's preferences counts */
    abstract public function key(): string;

    /** the list route below /api/gpos/{id}/ */
    abstract public function endpoint(): string;

    /** computer | user: the tab the section is in */
    abstract public function scope(): string;

    /** section title, e.g. "Netzlaufwerke" */
    abstract public function title(): string;

    /** name of the area in the GPMC, e.g. "Laufwerkszuordnungen" */
    abstract public function area(): string;

    /** count in the section head and the GPO list: "1 Laufwerk" / "4 Laufwerke" */
    abstract public function count(int $n): string;

    /** count in the GPO list, where the section title is missing: "2 Netzlaufwerke" */
    public function listCount(int $n): string
    {
        return $this->count($n);
    }

    /** PK95 AD version that brought the list */
    abstract public function since(): string;

    /** one line above the table */
    abstract public function intro(): string;

    /** words the search finds the section by */
    abstract public function searchWords(): string;

    /** "Netzlaufwerk hinzufügen" */
    abstract public function addLabel(): string;

    /** "Noch keine Netzlaufwerke." */
    abstract public function emptyText(): string;

    /** title of the editor of a new row */
    abstract public function newTitle(): string;

    /**
     * Short name of a row for button titles and the editor head, e.g. "V: \\nas\vertrieb".
     *
     * @param  array<string, mixed>  $row
     */
    abstract public function rowLabel(array $row): string;

    /**
     * Head of the editor of an existing row.
     *
     * @param  array<string, mixed>  $row
     */
    public function editorTitle(array $row): string
    {
        return $this->rowLabel($row);
    }

    /** what the condition sentence ends with: "bekommen dieses Laufwerk" */
    abstract public function gets(): string;

    /**
     * Kind fields of a new row.
     *
     * @param  list<array<string, mixed>>  $rows  the rows so far (e.g. for a free drive letter)
     * @return array<string, mixed>
     */
    abstract public function blank(array $rows): array;

    /**
     * API item → kind fields of the form row.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    abstract public function fieldsFromApi(array $item): array;

    /**
     * Form row → kind fields of the API item (in the order the API documents).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    abstract public function fieldsToApi(array $row): array;

    /**
     * Text the search looks into, besides the group name.
     *
     * @param  array<string, mixed>  $row
     */
    abstract public function searchText(array $row): string;

    /**
     * Table head of the kind's columns, as rendered by its cells view.
     *
     * @return list<array{0: string, 1: string}> label, classes of the <th>
     */
    abstract public function columns(): array;

    /** the field of the condition in the API ("target" in every list of PK95 AD) */
    public function audienceField(): string
    {
        return 'target';
    }

    /**
     * Checks before anything is sent: field (below the row) → message.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    public function check(array $row): array
    {
        return [];
    }

    public function max(): int
    {
        return 50;
    }

    /** tooltip next to the intro */
    public function hint(): ?string
    {
        return null;
    }

    /** "Höchstens 50 Netzlaufwerke je Gruppenrichtlinie." */
    public function maxText(): string
    {
        return 'Höchstens '.$this->max().' '.$this->title().' je Gruppenrichtlinie.';
    }

    /**
     * Titles of the order buttons.
     *
     * @return array{0: string, 1: string} earlier, later
     */
    public function moveLabels(): array
    {
        return ['früher anwenden', 'später anwenden'];
    }

    /**
     * Help per action, shown under the action select.
     *
     * @return array<string, string>
     */
    public function actionHelp(): array
    {
        return [];
    }

    /** who "all" is: Alle Benutzer (user configuration) or Alle Computer */
    public function everyone(): string
    {
        return $this->scope() === 'computer' ? 'Alle Computer' : 'Alle Benutzer';
    }

    /** @return array<string, string> the options of the condition */
    public function conditions(): array
    {
        return [
            'all' => $this->everyone(),
            'member' => 'Nur Mitglieder der Gruppe',
            'not_member' => 'Alle außer Mitgliedern der Gruppe',
        ];
    }

    /**
     * "Gilt für" of a row: Alle Benutzer / Mitglieder von X / Alle außer Mitgliedern von X.
     *
     * @param  array<string, mixed>  $row
     */
    public function audience(array $row, ?string $groupName = null): string
    {
        if ($row['condition'] === 'all' || $row['group'] === '') {
            return $this->everyone();
        }
        $name = $groupName ?: ($row['group_name'] ?: $row['group']);

        return $row['condition'] === 'not_member' ? 'Alle außer Mitgliedern von '.$name : 'Mitglieder von '.$name;
    }

    /** the view of a part of this kind: cells (the <td>s of a row) or fields (the editor) */
    public function view(string $part): string
    {
        return 'modules.ad.partials.gpp.'.$this->key().'.'.$part;
    }

    /**
     * API item → form row: the kind fields plus uid, condition, group, changed_at, read-only
     * flag and warning; read-only items keep the item as it came, they go back unchanged.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function toForm(array $item): array
    {
        $audience = is_array($item[$this->audienceField()] ?? null) ? $item[$this->audienceField()] : null;
        $readonly = (bool) ($item['readonly'] ?? false);

        return ['uid' => (string) ($item['uid'] ?? '')] + $this->fieldsFromApi($item) + [
            'condition' => $audience === null ? 'all' : (! empty($audience['not']) ? 'not_member' : 'member'),
            'group' => (string) ($audience['group_id'] ?? ''),
            'group_name' => (string) ($audience['group_name'] ?? ''),
            'changed_at' => (int) ($item['changed_at'] ?? 0),
            'readonly' => $readonly,
            'warning' => (string) ($item['warning'] ?? ''),
            'raw' => $readonly ? $item : null,
        ];
    }

    /**
     * Form row → API item: new items without uid, existing ones with it; read-only items
     * exactly as they came.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function toApi(array $row): array
    {
        if ($row['readonly'] && is_array($row['raw'])) {
            return $row['raw'];
        }
        $item = $this->fieldsToApi($row) + [
            $this->audienceField() => $row['condition'] !== 'all' && $row['group'] !== ''
                ? ['group_id' => (string) $row['group'], 'not' => $row['condition'] === 'not_member']
                : null,
        ];

        return $row['uid'] !== '' ? ['uid' => $row['uid']] + $item : $item;
    }

    /**
     * A new form row.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function newRow(array $rows): array
    {
        return $this->toForm($this->blank($rows));
    }

    /**
     * Form field of a 422 field below "items.": "2.target.group_id" → "2.group"; a kind maps its
     * own nested fields (e.g. "2.members.1.id" → "2.members").
     */
    public function errorField(string $rest): string
    {
        return (string) preg_replace('/\.'.preg_quote($this->audienceField(), '/').'(\.group_id)?$/', '.group', $rest);
    }
}
