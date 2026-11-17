<?php

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The data of Core on the LXC (/opt/pk95/core = storage, /opt/pk95/backups) is for its owner
 * and group only: the database holds the app tokens and password hashes, the backups the
 * apps' secrets.
 */
class DockerFilesTest extends TestCase
{
    private function file(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/docker/'.$name);
    }

    public function test_new_files_are_not_readable_for_others(): void
    {
        // the entrypoint (runs as root: migrate, caches) and everything supervisord starts
        $this->assertMatchesRegularExpression('/^umask 027$/m', $this->file('entrypoint.sh'));
        $this->assertMatchesRegularExpression('/^\[supervisord\][^\[]*^umask=027$/ms', $this->file('supervisord.conf'));
    }

    public function test_the_entrypoint_tightens_what_earlier_versions_left(): void
    {
        $entry = $this->file('entrypoint.sh');

        // storage = /opt/pk95/core: folders 0750, files 0640, after the chown to www-data
        $this->assertStringContainsString("find storage -type d -exec chmod 0750 {} +\nfind storage -type f -exec chmod 0640 {} +\n", $entry);
        $this->assertLessThan(strpos($entry, 'find storage -type d'), strpos($entry, 'chown -R www-data:www-data storage'));
        // the key and the setup code stay 0600
        $this->assertGreaterThan(strpos($entry, 'find storage -type f'), strrpos($entry, 'chmod 600 storage/app/private/app.key'));
        // backups: the folder of www-data 0750, archives (from root helpers) 0640
        $this->assertStringContainsString('chmod 0750 "${PK95_DATA_DIR:-/opt/pk95}/backups"', $entry);
        $this->assertStringContainsString('find "${PK95_DATA_DIR:-/opt/pk95}/backups" -type f -exec chmod 0640 {} +', $entry);
    }

    public function test_sqlite_gives_its_side_files_the_mode_of_the_database(): void
    {
        // what the entrypoint relies on: chmod 0640 on database.sqlite covers -wal and -shm
        $dir = sys_get_temp_dir().'/pk95-perm-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $db = $dir.'/database.sqlite';
        touch($db);
        chmod($db, 0640);
        $old = umask(0022);
        try {
            $pdo = new PDO('sqlite:'.$db);
            $pdo->exec('PRAGMA journal_mode=WAL; CREATE TABLE t (v TEXT); INSERT INTO t VALUES (1);');

            foreach (['-wal', '-shm'] as $suffix) {
                $this->assertFileExists($db.$suffix);
                $this->assertSame('0640', substr(sprintf('%o', fileperms($db.$suffix)), -4), $suffix);
            }
        } finally {
            umask($old);
            $pdo = null;
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }
}
