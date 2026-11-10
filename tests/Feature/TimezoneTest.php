<?php

namespace Tests\Feature;

use App\Http\Support\Format;
use App\Models\AppEvent;
use App\Modules\Dns\Overview as DnsOverview;
use App\Modules\Proxy\Certificates as ProxyCertificates;
use App\Modules\Proxy\Overview as ProxyOverview;
use App\Services\Catalog\AppDefinition;
use App\Services\Docker\ContainerSpec;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Times are stored in UTC and shown in the display timezone (pk95.timezone, Europe/Berlin
 * by default), including summer/winter time.
 */
class TimezoneTest extends FeatureTestCase
{
    private static function ms(string $utc): int
    {
        return Carbon::parse($utc, 'UTC')->getTimestampMs();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_display_timezone_comes_from_config_with_a_safe_fallback(): void
    {
        $this->assertSame('UTC', config('app.timezone'), 'stored times stay UTC');
        $this->assertSame('Europe/Berlin', Format::timezone());

        config(['pk95.timezone' => 'America/New_York']);
        $this->assertSame('America/New_York', Format::timezone());

        config(['pk95.timezone' => 'Mars/Olympus']);
        $this->assertSame('Europe/Berlin', Format::timezone());

        config(['pk95.timezone' => '']);
        $this->assertSame('Europe/Berlin', Format::timezone());
    }

    public function test_utc_times_are_shown_in_local_summer_and_winter_time(): void
    {
        $this->assertSame('01.07.2026 12:00', Format::dt('2026-07-01T10:00:00Z'));
        $this->assertSame('10.11.2026 18:53', Format::dt(Carbon::parse('2026-11-10 17:53:00', 'UTC')));
        $this->assertSame('10.11.2026 18:53:07', Format::dt('2026-11-10T17:53:07+00:00', true));
        $this->assertSame('18:53:07', Format::time('2026-11-10T17:53:07Z'));
        $this->assertSame('10.11.2026 18:53', Format::fromMs(self::ms('2026-11-10 17:53'))->format('d.m.Y H:i'));
        $this->assertNull(Format::local('kein Datum'));

        // the stored value is not touched
        $stored = Carbon::parse('2026-11-10 17:53:00', 'UTC');
        Format::local($stored);
        $this->assertSame('UTC', $stored->timezone->getName());
    }

    public function test_events_are_stored_in_utc_and_listed_in_local_time(): void
    {
        $this->signIn();
        Carbon::setTestNow(Carbon::parse('2026-11-10 17:53:07', 'UTC'));
        AppEvent::record(AppEvent::CORE, 'core.test', 'info', 'Zeitprobe');

        $this->assertSame('2026-11-10 17:53:07', DB::table('app_events')->value('occurred_at'));
        $this->get('/events')->assertOk()->assertSee('10.11. 18:53:07')->assertSee('10.11.2026 18:53:07')->assertDontSee('17:53');
        $this->get('/')->assertOk()->assertSee('10.11. 18:53');
    }

    public function test_dns_hour_buckets_are_labelled_in_local_hours(): void
    {
        // the bucket from the bug report: 16:00 UTC is 17:00–18:00 in Berlin
        $chart = DnsOverview::chart([['t' => self::ms('2026-11-10 16:00'), 'queries' => 5, 'blocked' => 1]], false);
        $this->assertStringStartsWith("10.11. 17:00 bis 18:00\n", $chart['bars'][0]['title']);
        $this->assertSame('17:00', $chart['labels'][0]['text']);

        // clocks go back on 25.10.2026 at 03:00 CEST: the hour 02:00–03:00 exists twice
        $series = array_map(fn ($h) => ['t' => self::ms($h), 'queries' => 1, 'blocked' => 0],
            ['2026-10-24 23:00', '2026-10-25 00:00', '2026-10-25 01:00', '2026-10-25 02:00']);
        $titles = array_map(fn ($b) => strstr($b['title'], "\n", true), DnsOverview::chart($series, false)['bars']);
        $this->assertSame(['25.10. 01:00 bis 02:00', '25.10. 02:00 bis 02:00', '25.10. 02:00 bis 03:00', '25.10. 03:00 bis 04:00'], $titles);

        // clocks go forward on 29.03.2026 at 02:00 CET: there is no 02:00–03:00
        $series = array_map(fn ($h) => ['t' => self::ms($h), 'queries' => 1, 'blocked' => 0],
            ['2026-03-29 00:00', '2026-03-29 01:00']);
        $titles = array_map(fn ($b) => strstr($b['title'], "\n", true), DnsOverview::chart($series, false)['bars']);
        $this->assertSame(['29.03. 01:00 bis 03:00', '29.03. 03:00 bis 04:00'], $titles);
    }

    public function test_dns_week_chart_puts_the_date_at_local_midnight(): void
    {
        // 7 days of hours from 09.11. 00:00 local (= 08.11. 23:00 UTC)
        $start = self::ms('2026-11-08 23:00');
        $series = [];
        for ($i = 0; $i < 168; $i++) {
            $series[] = ['t' => $start + $i * 3600000, 'queries' => 1, 'blocked' => 0];
        }
        $labels = array_column(DnsOverview::chart($series, false)['labels'], 'text');

        $this->assertSame(['09.11.', '10.11.', '11.11.', '12.11.', '13.11.', '14.11.', '15.11.'], $labels);
    }

    public function test_proxy_bucket_labels_are_local_across_the_clock_change(): void
    {
        $this->assertSame('10.11. 17:00 bis 18:00', ProxyOverview::bucketLabel(self::ms('2026-11-10 16:00'), 3600000));
        $this->assertSame('25.10. 02:00 bis 02:00', ProxyOverview::bucketLabel(self::ms('2026-10-25 00:00'), 3600000));
        $this->assertSame('18:50 bis 18:55', ProxyOverview::bucketLabel(self::ms('2026-11-10 17:50'), 300000));
    }

    public function test_certificate_days_left_do_not_depend_on_the_time_of_day(): void
    {
        $expires = Carbon::parse('2026-11-15 23:30:00', 'UTC')->getTimestamp(); // 16.11. 00:30 local

        // just before local midnight (5 d 0:45 left), just after it while UTC is still on 10.11.
        // (5 d 0:15) and just after UTC midnight (4 d 23:15): only the remaining time counts
        foreach (['2026-11-10 22:45' => 5, '2026-11-10 23:15' => 5, '2026-11-11 00:15' => 4] as $now => $days) {
            Carbon::setTestNow(Carbon::parse($now, 'UTC'));
            $this->assertSame($days, ProxyCertificates::daysLeft($expires), $now);
        }

        // exactly 5 days, one second less, expired
        Carbon::setTestNow(Carbon::parse('2026-11-10 23:30:00', 'UTC'));
        $this->assertSame(['warn', '16.11.2026 (5 Tage)'], ProxyCertificates::validity($expires));
        Carbon::setTestNow(Carbon::parse('2026-11-10 23:30:01', 'UTC'));
        $this->assertSame(['warn', '16.11.2026 (4 Tage)'], ProxyCertificates::validity($expires));
        Carbon::setTestNow(Carbon::parse('2026-11-15 23:30:01', 'UTC'));
        $this->assertSame(['bad', '16.11.2026 (abgelaufen)'], ProxyCertificates::validity($expires));
    }

    public function test_apps_get_the_display_timezone_as_tz(): void
    {
        $app = AppDefinition::fromArray(['id' => 'uptime', 'name' => 'Uptime', 'image' => 'ghcr.io/x/uptime', 'port' => 3001], 'uptime.yaml');

        $this->assertContains('TZ=Europe/Berlin', ContainerSpec::for($app, '1.0.0', 'token')['Env']);

        config(['pk95.timezone' => 'America/New_York']);
        $this->assertContains('TZ=America/New_York', ContainerSpec::for($app, '1.0.0', 'token')['Env']);
    }

    public function test_maintenance_window_uses_local_time(): void
    {
        Settings::put('maintenance_time', '03:30');

        // 02:30 UTC is 03:30 in Berlin (winter time)
        Carbon::setTestNow(Carbon::parse('2026-11-10 02:30', 'UTC'));
        $this->artisan('pk95:maintenance')->assertSuccessful();
        $this->assertSame('2026-11-10', Settings::get('maintenance_last_run'));

        // 03:30 UTC is already 04:30 local: outside the window, no run
        Settings::put('maintenance_last_run', '');
        Carbon::setTestNow(Carbon::parse('2026-11-11 03:30', 'UTC'));
        $this->artisan('pk95:maintenance')->assertSuccessful();
        $this->assertSame('', Settings::get('maintenance_last_run'));
    }

    public function test_maintenance_runs_on_the_local_date_and_survives_the_spring_gap(): void
    {
        // 00:15 local on 11.11. is still 10.11. in UTC
        Settings::put('maintenance_time', '00:15');
        Carbon::setTestNow(Carbon::parse('2026-11-10 23:15', 'UTC'));
        $this->artisan('pk95:maintenance')->assertSuccessful();
        $this->assertSame('2026-11-11', Settings::get('maintenance_last_run'));

        // 02:30 does not exist on 29.03.2026; at 03:00 local (01:00 UTC) the window is open
        Settings::put('maintenance_time', '02:30');
        Carbon::setTestNow(Carbon::parse('2026-03-29 01:00', 'UTC'));
        $this->artisan('pk95:maintenance')->assertSuccessful();
        $this->assertSame('2026-03-29', Settings::get('maintenance_last_run'));
    }
}
