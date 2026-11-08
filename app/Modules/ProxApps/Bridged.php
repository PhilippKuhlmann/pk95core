<?php

namespace App\Modules\ProxApps;

use App\Models\InstalledApp;
use App\Services\Apps\AppException;
use Illuminate\Support\Str;
use Livewire\Component;
use ReflectionClass;
use ReflectionProperty;

/**
 * A Core page that is a thin client of one of ProxApps' own Livewire components
 * (POST /api/bridge/{component}). The page declares the same public properties as the
 * original, so the ported view binds exactly like it did in ProxApps; every action and
 * every field change is executed by the original component in the ProxApps container.
 */
trait Bridged
{
    public InstalledApp $installedApp;

    public ?string $bridgeError = null;

    /** View data of the last answer (not kept between requests: rebuilt on demand). */
    protected array $view = [];

    abstract protected function component(): string;

    abstract protected function viewName(): string;

    /**
     * Arguments for the original mount() (model ids, app id …).
     *
     * @return array<string, mixed>
     */
    protected function mountArgs(): array
    {
        return [];
    }

    /**
     * Query string the original reads (e.g. ?from= for cloning, ?page= for pagination).
     *
     * @return array<string, mixed>
     */
    protected function query(): array
    {
        return [];
    }

    public function mount(): void
    {
        $this->bridge(['mount' => $this->mountArgs(), 'query' => $this->query()]);
    }

    /** Livewire hook: a bound field changed - let the original react (updated hooks). */
    public function updated(string $property): void
    {
        if (in_array(Str::before($property, '.'), $this->bridgedProperties(), true)) {
            $this->bridge(['state' => $this->bridgeState(), 'updated' => $property, 'query' => $this->query()]);
        }
    }

    /**
     * Run a public method of the original component.
     *
     * @param  array<string, mixed>  $params  named parameters
     */
    protected function remote(string $method, array $params = []): void
    {
        $this->bridge(['state' => $this->bridgeState(), 'call' => ['method' => $method, 'params' => $params], 'query' => $this->query()]);
    }

    /**
     * Link to another ProxApps page inside Core (used by the ported views).
     *
     * @param  array<string, mixed>  $query
     */
    public function pageUrl(string $page, array $query = []): string
    {
        return route('apps.page', [$this->installedApp, $page]).($query ? '?'.http_build_query($query) : '');
    }

    /** "Erneut versuchen" on the unreachable page: render asks ProxApps again. */
    public function retry(): void
    {
        $this->bridgeError = null;
    }

    public function render()
    {
        if ($this->view === [] && $this->bridgeError === null) {
            $this->bridge(['state' => $this->bridgeState(), 'query' => $this->query()]);
        }
        // ProxApps unreachable before it sent any data: the page itself cannot render without
        // its view data, so show the reason and a retry instead.
        if ($this->view === [] && $this->bridgeError !== null) {
            return view('modules.proxapps.unreachable', ['bridgeError' => $this->bridgeError]);
        }

        // $remote: all view data of the original. Livewire hands public properties to the view
        // after the data passed here, so a model property (only {"__model": id} in the state)
        // would hide its data under the same name - views read models from $remote.
        return view($this->viewName(), $this->view + ['bridgeError' => $this->bridgeError, 'remote' => new BridgeData($this->view)]);
    }

    /**
     * Public properties of the Core page that are not mirrored to ProxApps.
     *
     * @return list<string>
     */
    protected function localProperties(): array
    {
        return [];
    }

    // ------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $body
     */
    protected function bridge(array $body): void
    {
        $body['errors'] = $this->getErrorBag()->toArray();
        try {
            $response = $this->installedApp->client()->post('/api/bridge/'.$this->component(), $body);
        } catch (AppException $e) {
            $this->bridgeError = $e->getMessage();

            return;
        }
        if (! $response->successful()) {
            $message = (string) ($response->json('error') ?? $response->json('message') ?? "ProxApps antwortet mit HTTP {$response->status()}.");
            $this->dispatch('notice', kind: 'error', text: $message);
            $this->bridgeError = $response->status() >= 500 ? $message : null;

            return;
        }
        $this->bridgeError = null;

        foreach ((array) $response->json('state') as $key => $value) {
            if (in_array($key, $this->bridgedProperties(), true)) {
                $this->{$key} = $value;
            }
        }
        $this->resetErrorBag();
        foreach ((array) $response->json('errors') as $key => $messages) {
            foreach ((array) $messages as $message) {
                $this->addError((string) $key, (string) $message);
            }
        }
        foreach ((array) $response->json('notices') as $notice) {
            $this->dispatch('notice', kind: $notice['kind'] ?? 'success', text: $notice['text'] ?? '');
        }
        foreach ((array) $response->json('events') as $event) {
            if (is_string($event['name'] ?? null)) {
                $this->dispatch($event['name'], ...(array) ($event['params'] ?? []));
            }
        }
        if ($to = $response->json('redirect')) {
            $this->redirect($this->mapRedirect((string) $to));

            return;
        }
        $this->view = array_map(fn ($v) => BridgeData::wrap($v), (array) $response->json('view'));
    }

    /**
     * A redirect of the original (ProxApps path) → the matching Core page.
     */
    protected function mapRedirect(string $path): string
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        return match (true) {
            ($segments[0] ?? '') === 'deployments' && isset($segments[1]) => $this->pageUrl('deployment', ['id' => $segments[1]]),
            ($segments[0] ?? '') === 'deployments' => $this->pageUrl('deployments'),
            ($segments[0] ?? '') === 'connections' && isset($segments[1]) && is_numeric($segments[1]) => $this->pageUrl('connection-form', ['id' => $segments[1]]),
            ($segments[0] ?? '') === 'connections' => $this->pageUrl('connections'),
            ($segments[0] ?? '') === 'apps' && isset($segments[1]) => $this->pageUrl('deploy', ['app' => $segments[1]]),
            in_array($segments[0] ?? '', ['apps', 'updates', 'settings'], true) => $this->pageUrl($segments[0]),
            default => $this->pageUrl('dashboard'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function bridgeState(): array
    {
        $state = [];
        foreach ($this->bridgedProperties() as $name) {
            $state[$name] = $this->{$name};
        }

        return $state;
    }

    /**
     * Public properties mirrored from the original (all public ones of the page class
     * except Core's own).
     *
     * @return list<string>
     */
    protected function bridgedProperties(): array
    {
        return array_values(array_map(
            fn (ReflectionProperty $p) => $p->getName(),
            array_filter(
                (new ReflectionClass($this))->getProperties(ReflectionProperty::IS_PUBLIC),
                fn (ReflectionProperty $p) => ! $p->isStatic()
                    && ! in_array($p->getName(), ['installedApp', 'bridgeError', ...$this->localProperties()], true)
                    && $p->getDeclaringClass()->getName() !== Component::class,
            ),
        ));
    }
}
