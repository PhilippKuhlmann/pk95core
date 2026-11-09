<?php

namespace App\Services\Automation;

use App\Services\Docker\DockerClient;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * LAN IP of the LXC that runs PK95 Proxy: the answer of the local DNS records Core creates
 * for deployed apps. Core itself only sees its bridge network (172.x), and neither Proxy
 * nor DNS reports the address, so a short helper container in the host network asks the
 * kernel which source address it uses towards the internet. A value in the settings wins.
 */
class LanIp
{
    public const CACHE = 'automation.lan_ip';

    /** Re-detect after a failure only every few minutes (each try starts a container). */
    private const RETRY_SECONDS = 300;

    public function __construct(private readonly DockerClient $docker) {}

    /** The address to use: setting, else detected. */
    public function current(): ?string
    {
        $fixed = trim((string) Settings::get('automation_lan_ip'));

        return $fixed !== '' ? $fixed : $this->detected()['ip'];
    }

    /**
     * Last detection (cached), or a new one when there is none yet or $fresh.
     *
     * @return array{ip: ?string, at: ?string, error: ?string}
     */
    public function detected(bool $fresh = false): array
    {
        $cached = Cache::get(self::CACHE);
        if (! $fresh && is_array($cached)) {
            return $cached;
        }
        $result = $this->detect();
        Cache::put(self::CACHE, $result, $result['ip'] !== null ? now()->addDay() : now()->addSeconds(self::RETRY_SECONDS));

        return $result;
    }

    /**
     * @return array{ip: ?string, at: ?string, error: ?string}
     */
    private function detect(): array
    {
        $at = now()->toIso8601String();
        try {
            // busybox: "1.1.1.1 via 10.10.250.1 dev eth0  src 10.10.250.50"
            $run = $this->docker->run((string) config('pk95.helper_image'), ['ip', '-4', 'route', 'get', '1.1.1.1'], [], 30, 'host');
            if ($run['exit'] === 0 && preg_match('/\bsrc\s+(\d{1,3}(?:\.\d{1,3}){3})\b/', $run['output'], $m)) {
                return ['ip' => $m[1], 'at' => $at, 'error' => null];
            }

            // Docker log lines start with an RFC 3339 timestamp.
            $output = trim((string) preg_replace('/^\S+Z\s/m', '', $run['output']));

            return ['ip' => null, 'at' => $at, 'error' => 'Keine Route ins Internet gefunden'.($output !== '' ? ': '.rtrim($output, '.').'.' : '.')];
        } catch (Throwable $e) {
            return ['ip' => null, 'at' => $at, 'error' => $e->getMessage()];
        }
    }
}
