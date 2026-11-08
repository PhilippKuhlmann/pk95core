<?php

namespace Tests\Unit;

use App\Services\Docker\DockerClient;
use App\Services\Docker\DockerException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DockerClientTest extends TestCase
{
    private function client(): DockerClient
    {
        return new DockerClient('/var/run/docker.sock', 'v1.44');
    }

    public function test_requests_go_through_the_unix_socket(): void
    {
        Http::fake(['docker/v1.44/version' => Http::response(['Version' => '28.0.1'])]);

        $this->assertSame('28.0.1', $this->client()->version()['Version']);
        Http::assertSent(fn (Request $r) => $r->url() === 'http://docker/v1.44/version');
    }

    public function test_pull_reports_errors_from_the_progress_stream(): void
    {
        Http::fake(['docker/v1.44/images/create*' => Http::response("{\"status\":\"Pulling\"}\n{\"errorDetail\":{\"message\":\"manifest unknown\"},\"error\":\"manifest unknown\"}\n")]);

        $this->expectException(DockerException::class);
        $this->expectExceptionMessage('manifest unknown');
        $this->client()->pull('ghcr.io/x/y', '9.9');
    }

    public function test_pull_passes_image_and_tag(): void
    {
        Http::fake(['docker/v1.44/images/create*' => Http::response('{"status":"Done"}')]);

        $this->client()->pull('ghcr.io/x/y', '1.0');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_contains($r->url(), 'fromImage=ghcr.io%2Fx%2Fy') && str_contains($r->url(), 'tag=1.0'));
    }

    public function test_missing_container_is_null_other_errors_throw(): void
    {
        Http::fake([
            'docker/v1.44/containers/gone/json' => Http::response(['message' => 'No such container'], 404),
            'docker/v1.44/containers/broken/json' => Http::response(['message' => 'boom'], 500),
        ]);

        $this->assertNull($this->client()->inspectContainer('gone'));

        $this->expectException(DockerException::class);
        $this->expectExceptionMessage('boom');
        $this->client()->inspectContainer('broken');
    }

    public function test_starting_a_running_container_is_fine(): void
    {
        Http::fake(['docker/v1.44/containers/abc/start' => Http::response('', 304)]);

        $this->client()->startContainer('abc');
        $this->addToAssertionCount(1);
    }

    public function test_unreachable_socket(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $this->assertFalse($this->client()->available());
        $this->expectException(DockerException::class);
        $this->expectExceptionMessage('Docker ist nicht erreichbar');
        $this->client()->version();
    }

    public function test_demux_splits_stdout_and_stderr_frames(): void
    {
        $raw = chr(1)."\0\0\0".pack('N', 12)."line one\nl2\n".chr(2)."\0\0\0".pack('N', 6)."oops!\n";

        $this->assertSame([
            ['stream' => 'stdout', 'line' => 'line one'],
            ['stream' => 'stdout', 'line' => 'l2'],
            ['stream' => 'stderr', 'line' => 'oops!'],
        ], DockerClient::demux($raw));
    }

    public function test_demux_handles_tty_output(): void
    {
        $this->assertSame([['stream' => 'stdout', 'line' => 'plain'], ['stream' => 'stdout', 'line' => 'text']], DockerClient::demux("plain\ntext\n"));
    }
}
