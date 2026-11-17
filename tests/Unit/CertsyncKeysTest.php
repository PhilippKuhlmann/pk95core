<?php

namespace Tests\Unit;

use App\Services\Certsync\Certsync;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Public keys for the SFTP user certsync: ssh-ed25519, ecdsa-sha2-* and ssh-rsa from 3072 bit,
 * one per line, without options (command=, from= …).
 */
class CertsyncKeysTest extends TestCase
{
    private static function str(string $s): string
    {
        return pack('N', strlen($s)).$s;
    }

    /** A key line in OpenSSH format with random key material of the right shape. */
    public static function key(string $type, int $rsaBits = 4096, string $comment = 'mac@buero'): string
    {
        $blob = self::str($type).match (true) {
            $type === 'ssh-ed25519' => self::str(random_bytes(32)),
            $type === 'ssh-rsa' => self::str("\x01\x00\x01").self::str("\x00".chr(0xC0 | random_int(0, 63)).random_bytes(intdiv($rsaBits, 8) - 1)),
            default => self::str(substr($type, strlen('ecdsa-sha2-'))).self::str("\x04".random_bytes(64)),
        };

        return $type.' '.base64_encode($blob).($comment !== '' ? ' '.$comment : '');
    }

    private function rejects(string $line, string $message): void
    {
        try {
            Certsync::parse($line);
            $this->fail('accepted: '.$line);
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_accepts_ed25519_ecdsa_and_rsa_from_3072_bit(): void
    {
        $ed = Certsync::parse(self::key('ssh-ed25519'));
        $this->assertSame(['ssh-ed25519', 256, 'mac@buero'], [$ed['type'], $ed['bits'], $ed['comment']]);
        $blob = base64_decode(explode(' ', self::key('ssh-ed25519'))[1]);
        $this->assertSame('SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '='),
            Certsync::parse('ssh-ed25519 '.base64_encode($blob))['fingerprint'], 'like ssh-keygen -lf');

        $this->assertSame(4096, Certsync::parse(self::key('ssh-rsa', 4096))['bits']);
        $this->assertSame(3072, Certsync::parse(self::key('ssh-rsa', 3072))['bits']);
        $this->assertSame(384, Certsync::parse(self::key('ecdsa-sha2-nistp384'))['bits']);
        // spaces around and between are fine, the line is written normalized
        $line = self::key('ssh-ed25519', comment: '');
        $this->assertSame($line, Certsync::parse("  {$line}  ")['line']);
    }

    public function test_rejects_weak_foreign_and_broken_keys(): void
    {
        $this->rejects(self::key('ssh-rsa', 2048), 'mindestens 3072 Bit, dieser hat 2048');
        $this->rejects('ssh-dss '.base64_encode(self::str('ssh-dss').self::str('x')), 'Erlaubt sind ssh-ed25519, ecdsa-sha2-* und ssh-rsa ab 3072 Bit.');
        $this->rejects('ssh-ed25519 kein-base64!', 'kein Base64');
        // the blob names another type, a wrong curve, cut off, trailing data
        $this->rejects('ssh-ed25519 '.explode(' ', self::key('ssh-rsa'))[1], 'passt nicht zu seinem Typ');
        $this->rejects('ecdsa-sha2-nistp256 '.base64_encode(self::str('ecdsa-sha2-nistp256').self::str('nistp384').self::str("\x04")), 'Kurve');
        $this->rejects('ssh-ed25519 '.base64_encode(self::str('ssh-ed25519').pack('N', 32).'kurz'), 'gekürzt');
        $this->rejects('ssh-ed25519 '.base64_encode(self::str('ssh-ed25519').self::str(random_bytes(32)).'x'), 'Daten am Ende');
        $this->rejects(self::key('ssh-ed25519')."\n".self::key('ssh-ed25519'), 'genau einen');
    }

    public function test_rejects_options(): void
    {
        $key = self::key('ssh-ed25519');
        $this->rejects('command="/bin/sh" '.$key, 'Optionen wie command= oder from= sind nicht erlaubt');
        $this->rejects('from="10.0.0.1",no-pty '.$key, 'Optionen');
        $this->rejects('restrict,pty '.$key, 'Optionen');
    }

    public function test_the_comment_is_cleaned_and_shortened(): void
    {
        [$type, $base64] = explode(' ', self::key('ssh-ed25519'));
        $key = Certsync::parse("{$type} {$base64} Büro\tMac ".str_repeat('x', 200));

        // only printable ASCII stays (the umlaut and the tab go), at most 100 characters
        $this->assertSame(100, strlen($key['comment']));
        $this->assertStringStartsWith('BroMac xxx', $key['comment']);
        $this->assertDoesNotMatchRegularExpression('/[^\x20-\x7E]/', $key['line']);
    }
}
