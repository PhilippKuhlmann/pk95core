<?php

namespace App\Livewire\Dashboard;

use App\Services\Dashboard\Problems;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * "Probleme & Warnungen" at the top of the dashboard (lazy: it asks the apps).
 */
#[Lazy]
class ProblemList extends Component
{
    public function placeholder()
    {
        return view('livewire.dashboard.placeholder', ['title' => 'Probleme & Warnungen']);
    }

    public function render(Problems $problems)
    {
        return view('livewire.dashboard.problem-list', ['problems' => $problems->collect()]);
    }
}
