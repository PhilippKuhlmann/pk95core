<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * install/certsync.sh, the sshd part: a broken drop-in must never stay behind, Debian 13 runs
 * sshd -t before every start of sshd (ssh.socket) and SSH would be gone. The block between
 * "# --- sshd" and "# --- end sshd" runs here in bash with sshd and systemctl faked.
 */
class InstallScriptsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/pk95-certsync-sh-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/tmp', 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function conf(): string
    {
        return $this->dir.'/sshd_config.d/pk95-certsync.conf';
    }

    /**
     * Run the sshd block; $sshdFails: how many sshd -t calls fail (first the new file, then
     * the check after the rollback).
     *
     * @return array{exit: int, out: string, err: string, systemctl: string}
     */
    private function runSshdBlock(int $sshdFails): array
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/install/certsync.sh');
        $this->assertSame(1, preg_match('/^# --- sshd -+\n(.*)^# --- end sshd -+$/ms', $script, $m), 'the marked sshd block');
        $harness = <<<'SH'
set -euo pipefail
say() { echo "SAY $*"; }
fail() { echo "FAIL $*"; exit 1; }
sshd() {
    n="$(cat "$DIR/sshd-calls" 2>/dev/null || echo 0)"; echo $((n + 1)) > "$DIR/sshd-calls"
    if [ "$n" -lt "$SSHD_FAILS" ]; then echo "sshd: invalid" >&2; return 1; fi
}
# the script sends its output to /dev/null: write down the call instead
systemctl() { echo "$*" >> "$DIR/systemctl"; }
SH;
        $proc = proc_open(['bash', '-c', $harness."\n".$m[1]], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
            'DIR' => $this->dir, 'SSHD_FAILS' => (string) $sshdFails, 'SSHD_CONF' => $this->conf(),
            'TMPDIR' => $this->dir.'/tmp', 'PATH' => getenv('PATH'),
        ]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $exit = proc_close($proc);
        @unlink($this->dir.'/sshd-calls');
        $reloads = is_file($this->dir.'/systemctl') ? (string) file_get_contents($this->dir.'/systemctl') : '';
        @unlink($this->dir.'/systemctl');

        return ['exit' => $exit, 'out' => (string) $out, 'err' => (string) $err, 'systemctl' => $reloads];
    }

    public function test_a_valid_drop_in_is_written_and_only_then_sshd_reloads(): void
    {
        $run = $this->runSshdBlock(0);

        $this->assertSame(0, $run['exit']);
        $this->assertStringContainsString("Match User certsync\n  ChrootDirectory /srv/pk95\n  ForceCommand internal-sftp -R\n  AuthorizedKeysFile /opt/pk95/ssh/authorized_keys/%u", (string) file_get_contents($this->conf()));
        $this->assertSame("try-reload-or-restart ssh\n", $run['systemctl']);
        $this->assertSame([], glob($this->dir.'/tmp/*'), 'no backup left behind');

        // the same again: nothing to change, no reload
        $this->assertSame('', $this->runSshdBlock(0)['systemctl']);
    }

    public function test_a_broken_drop_in_without_a_previous_one_is_removed(): void
    {
        $run = $this->runSshdBlock(1);

        $this->assertSame(1, $run['exit']);
        $this->assertFileDoesNotExist($this->conf());
        $this->assertStringContainsString('FAIL Die neue SSH-Konfiguration für certsync war ungültig (sshd -t) und ist zurückgenommen.', $run['out']);
        $this->assertSame('', $run['systemctl'], 'no reload');
        $this->assertStringNotContainsString('Achtung', $run['err'], 'sshd -t is fine again after the rollback');
    }

    public function test_a_broken_drop_in_brings_the_previous_one_back(): void
    {
        mkdir(dirname($this->conf()));
        file_put_contents($this->conf(), "# the previous drop-in\nMatch User certsync\nMatch all\n");
        chmod($this->conf(), 0644);

        $run = $this->runSshdBlock(1);

        $this->assertSame(1, $run['exit']);
        $this->assertSame("# the previous drop-in\nMatch User certsync\nMatch all\n", file_get_contents($this->conf()));
        $this->assertSame('0644', substr(sprintf('%o', fileperms($this->conf())), -4));
        $this->assertSame('', $run['systemctl'], 'no reload');
        $this->assertSame([], glob($this->dir.'/tmp/*'), 'the backup is gone again');
    }

    public function test_it_warns_when_sshd_is_broken_without_the_new_file_too(): void
    {
        $run = $this->runSshdBlock(2);

        $this->assertSame(1, $run['exit']);
        $this->assertFileDoesNotExist($this->conf());
        $this->assertStringContainsString('Achtung: Die SSH-Konfiguration ist auch ohne die neue certsync-Datei ungültig.', $run['err']);
        $this->assertSame('', $run['systemctl'], 'no reload');
    }
}
