<?php

namespace App\Http\Controllers;

use App\Models\InstalledApp;
use App\Services\Apps\AppRegistry;
use Illuminate\View\View;

/**
 * Renders a page of a running app through its Core module: /apps/{app}/{page}.
 * The page component is resolved at runtime from the app's manifest and the module.
 */
class ModulePageController extends Controller
{
    public function __invoke(InstalledApp $installedApp, string $page, AppRegistry $registry): View
    {
        $component = $registry->page($installedApp, $page);
        abort_if($component === null, 404);

        // the menu label of the app, else the module's own title (pages without a menu entry)
        $label = collect($installedApp->manifest()?->menu ?? [])->firstWhere('key', $page)['label']
            ?? $registry->module($installedApp)?->pageTitle($page) ?? $page;

        return view('apps.module-page', [
            'installedApp' => $installedApp,
            'pageComponent' => $component,
            'title' => $label.' · '.$installedApp->displayName(),
        ]);
    }
}
