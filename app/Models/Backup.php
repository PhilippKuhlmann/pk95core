<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One backup of an app's volumes: one tar.gz per volume under /opt/pk95/backups/<app>/.
 *
 * @property string $app_id
 * @property string $reason manual|scheduled|update
 * @property string $status running|done|failed
 * @property array<string, string>|null $files volume name → file path
 * @property int $size bytes
 */
#[Fillable(['app_id', 'reason', 'status', 'files', 'size', 'version', 'error'])]
class Backup extends Model
{
    public const REASONS = ['manual' => 'Manuell', 'scheduled' => 'Geplant', 'update' => 'Vor Update'];

    protected function casts(): array
    {
        return ['files' => 'array', 'size' => 'integer'];
    }
}
