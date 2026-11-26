<?php

namespace App\Modules\File;

use App\Modules\File\Concerns\FilePage;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * PK95 File → Sitzungen: the SMB sessions (GET /api/sessions) with "Sitzung trennen"
 * (DELETE /api/sessions/{id}) and the open files (GET /api/open-files, filter by share). Samba
 * cannot close a single file, so a stuck file is freed by disconnecting its session.
 */
class Sessions extends Component
{
    use FilePage;

    /** filter of the open files: a share name, '' = all */
    #[Url(as: 'freigabe', except: '')]
    public string $share = '';

    public function disconnect(string $id): void
    {
        $response = $this->appRequest(fn ($api) => $api->delete('/api/sessions/'.rawurlencode($id)));
        if ($this->succeeded($response, '')) {
            $this->done('Sitzung getrennt. Windows verbindet beim nächsten Zugriff neu.');
        }
    }

    public function render()
    {
        [$sessions, $loadError] = $this->fetch('/api/sessions');
        $files = null;
        $filesError = null;
        if (! $this->notReady && $loadError === null) {
            [$files, $filesError] = $this->fetch('/api/open-files', array_filter(['share' => $this->share]));
        }
        $items = array_values((array) ($sessions['items'] ?? []));

        return view('modules.file.sessions', [
            'sessions' => $items,
            'files' => array_values((array) ($files['items'] ?? [])),
            'filesError' => $filesError,
            'loadError' => $loadError,
            'shareOptions' => collect($items)->flatMap(fn ($s) => (array) ($s['shares'] ?? []))
                ->merge($this->share !== '' ? [$this->share] : [])->unique()->sort()->mapWithKeys(fn ($n) => [$n => $n])->all(),
        ]);
    }
}
