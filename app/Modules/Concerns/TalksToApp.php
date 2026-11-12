<?php

namespace App\Modules\Concerns;

use App\Services\Apps\AppClient;
use App\Services\Apps\AppException;
use Illuminate\Http\Client\Response;

/**
 * For module pages (Livewire): call the app's API and turn its answers into toasts and
 * field errors. Apps answer validation errors as {error, fields: {"config.url": "…"}}.
 */
trait TalksToApp
{
    /**
     * @param  callable(AppClient): Response  $send
     */
    protected function appRequest(callable $send): ?Response
    {
        try {
            return $send($this->installedApp->client());
        } catch (AppException $e) {
            $this->dispatch('notice', kind: 'error', text: $e->getMessage());

            return null;
        }
    }

    /**
     * True for a 2xx answer. Otherwise field errors go to "$prefix<field>" and the app's
     * message becomes an error toast.
     */
    protected function succeeded(?Response $response, string $prefix = 'form.'): bool
    {
        if ($response === null) {
            return false;
        }
        if ($response->successful()) {
            return true;
        }
        foreach ((array) $response->json('fields') as $field => $message) {
            $this->addError($prefix.$this->mapField((string) $field), (string) $message);
        }
        $this->dispatch('notice', kind: 'error', text: (string) ($response->json('error') ?? "Die App antwortet mit HTTP {$response->status()}."));

        return false;
    }

    /**
     * Field name of the app → name in the form of this page.
     */
    protected function mapField(string $field): string
    {
        return $field;
    }

    /**
     * GET for render(): data or null, plus the reason when it failed (shown in the page,
     * not as a toast, because polling would repeat it).
     *
     * @param  array<string, mixed>|string  $query  string: sent as it is (repeated keys)
     * @return array{0: mixed, 1: ?string}
     */
    protected function load(string $path, array|string $query = []): array
    {
        try {
            $response = $this->installedApp->client()->get($path, $query);
        } catch (AppException $e) {
            return [null, $e->getMessage()];
        }
        if (! $response->successful()) {
            return [null, (string) ($response->json('error') ?? "Die App antwortet mit HTTP {$response->status()}.")];
        }

        return [$response->json(), null];
    }
}
