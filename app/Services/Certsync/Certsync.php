<?php

namespace App\Services\Certsync;

use App\Services\Docker\DockerClient;
use InvalidArgumentException;
use RuntimeException;

/**
 * The SFTP user certsync on the LXC (install/certsync.sh): it may only read the certificates
 * of PK95 Proxy below /srv/pk95. Its public keys are in /opt/pk95/ssh/authorized_keys/certsync
 * (sshd AuthorizedKeysFile). Core reads that file directly and writes it as root through a
 * helper container (sshd StrictModes: owned by root, not writable for group or others).
 */
final class Certsync
{
    public const MAX_KEYS = 20;

    /** key types and their size in bits (RSA: from the modulus, at least 3072) */
    public const TYPES = ['ssh-ed25519' => 256, 'ecdsa-sha2-nistp256' => 256, 'ecdsa-sha2-nistp384' => 384, 'ecdsa-sha2-nistp521' => 521, 'ssh-rsa' => 0];

    public const MIN_RSA_BITS = 3072;

    /** run this once on the Proxmox host for an existing installation */
    public const SETUP_COMMAND = 'pct exec <CTID> -- bash -c "$(curl -fsSL https://raw.githubusercontent.com/PhilippKuhlmann/pk95core/main/install/certsync.sh)"';

    public function __construct(private readonly DockerClient $docker) {}

    public static function keysFile(): string
    {
        return rtrim((string) config('pk95.data_dir'), '/').'/ssh/authorized_keys/certsync';
    }

    /** Written by install/certsync.sh: whether certsync is set up and with which ids. */
    public static function markerFile(): string
    {
        return rtrim((string) config('pk95.data_dir'), '/').'/ssh/certsync.env';
    }

    /**
     * @return array{gid: int, uid: int}|null null = not set up on the LXC yet
     */
    public static function setup(): ?array
    {
        $raw = is_readable(self::markerFile()) ? (string) file_get_contents(self::markerFile()) : '';
        if (! preg_match('/^CERTS_GID=(\d+)$/m', $raw, $gid)) {
            return null;
        }
        preg_match('/^CERTSYNC_UID=(\d+)$/m', $raw, $uid);

        return ['gid' => (int) $gid[1], 'uid' => (int) ($uid[1] ?? 0)];
    }

    /**
     * The keys in the file; lines that break the rules (options, other types) are counted:
     * Core leaves them out when it writes the file the next time.
     *
     * @return array{keys: list<array{type: string, bits: int, fingerprint: string, comment: string, line: string}>, ignored: int}
     */
    public static function keys(): array
    {
        $raw = is_readable(self::keysFile()) ? (string) file_get_contents(self::keysFile()) : '';
        $keys = [];
        $ignored = 0;
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }
            try {
                $key = self::parse($line);
                $keys[$key['fingerprint']] ??= $key;
            } catch (InvalidArgumentException) {
                $ignored++;
            }
        }

        return ['keys' => array_values($keys), 'ignored' => $ignored];
    }

    /**
     * One public key in OpenSSH format ("type base64 comment"), checked against the rules.
     *
     * @return array{type: string, bits: int, fingerprint: string, comment: string, line: string}
     *
     * @throws InvalidArgumentException with the reason for the user
     */
    public static function parse(string $line): array
    {
        $line = trim($line);
        if ($line === '' || preg_match('/\R/', $line)) {
            throw new InvalidArgumentException('Bitte genau einen öffentlichen Schlüssel in einer Zeile.');
        }
        [$type, $base64, $comment] = array_pad(preg_split('/\s+/', $line, 3) ?: [], 3, '');
        if (! isset(self::TYPES[$type])) {
            throw new InvalidArgumentException(preg_match('/[=,"]/', $type)
                ? 'Optionen wie command= oder from= sind nicht erlaubt. Bitte nur „Typ Schlüssel Kommentar“.'
                : 'Erlaubt sind ssh-ed25519, ecdsa-sha2-* und ssh-rsa ab '.self::MIN_RSA_BITS.' Bit.');
        }
        $blob = base64_decode($base64, true);
        if ($blob === false || $blob === '') {
            throw new InvalidArgumentException('Der Schlüssel ist nicht gültig (kein Base64).');
        }

        // SSH wire format: length-prefixed strings, the first one repeats the type
        $pos = 0;
        $read = function () use ($blob, &$pos): string {
            $length = strlen($blob) - $pos >= 4 ? unpack('N', substr($blob, $pos, 4))[1] : -1;
            if ($length < 0 || $length > strlen($blob) - $pos - 4) {
                throw new InvalidArgumentException('Der Schlüssel ist nicht gültig (beschädigt oder gekürzt).');
            }
            $value = substr($blob, $pos + 4, $length);
            $pos += 4 + $length;

            return $value;
        };
        if ($read() !== $type) {
            throw new InvalidArgumentException('Der Schlüssel passt nicht zu seinem Typ '.$type.'.');
        }
        $bits = self::TYPES[$type];
        if ($type === 'ssh-rsa') {
            $read(); // public exponent
            $modulus = ltrim($read(), "\0");
            $bits = $modulus === '' ? 0 : (strlen($modulus) - 1) * 8 + strlen(decbin(ord($modulus[0])));
            if ($bits < self::MIN_RSA_BITS) {
                throw new InvalidArgumentException('RSA-Schlüssel brauchen mindestens '.self::MIN_RSA_BITS.' Bit, dieser hat '.$bits.'.');
            }
        } elseif ($type === 'ssh-ed25519') {
            if (strlen($read()) !== 32) {
                throw new InvalidArgumentException('Der Schlüssel ist nicht gültig (ed25519 braucht 32 Byte).');
            }
        } else {
            if ($read() !== substr($type, strlen('ecdsa-sha2-'))) {
                throw new InvalidArgumentException('Der Schlüssel passt nicht zu seiner Kurve.');
            }
            $read(); // the point
        }
        if ($pos !== strlen($blob)) {
            throw new InvalidArgumentException('Der Schlüssel ist nicht gültig (Daten am Ende).');
        }

        // the comment only names the key: printable, short, nothing sshd could read as an option
        $comment = mb_substr(trim((string) preg_replace('/[^\x20-\x7E]/', '', $comment)), 0, 100);

        return [
            'type' => $type,
            'bits' => $bits,
            'fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '='),
            'comment' => $comment,
            'line' => $type.' '.base64_encode($blob).($comment !== '' ? ' '.$comment : ''),
        ];
    }

    /**
     * @return array{type: string, bits: int, fingerprint: string, comment: string, line: string}
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    public function add(string $line): array
    {
        $key = self::parse($line);
        $keys = self::keys()['keys'];
        if (in_array($key['fingerprint'], array_column($keys, 'fingerprint'), true)) {
            throw new InvalidArgumentException('Dieser Schlüssel ist schon eingetragen.');
        }
        if (count($keys) >= self::MAX_KEYS) {
            throw new InvalidArgumentException('Höchstens '.self::MAX_KEYS.' Schlüssel.');
        }
        $this->write([...array_column($keys, 'line'), $key['line']]);

        return $key;
    }

    /** @throws RuntimeException */
    public function remove(string $fingerprint): bool
    {
        $keys = self::keys()['keys'];
        $rest = array_values(array_filter($keys, fn ($k) => $k['fingerprint'] !== $fingerprint));
        if (count($rest) === count($keys)) {
            return false;
        }
        $this->write(array_column($rest, 'line'));

        return true;
    }

    /**
     * Replace the file as root (helper container): 0644 root:root, atomically via a temporary
     * file next to it. The lines are checked keys, quoted for the shell.
     *
     * @param  list<string>  $lines
     *
     * @throws RuntimeException
     */
    private function write(array $lines): void
    {
        $content = $lines === [] ? 'true' : "printf '%s\\n' ".implode(' ', array_map('escapeshellarg', $lines));
        $script = "umask 022 && {$content} > /keys/.certsync.new && chmod 0644 /keys/.certsync.new && mv -f /keys/.certsync.new /keys/certsync";
        try {
            $result = $this->docker->run((string) config('pk95.helper_image'), ['sh', '-c', $script],
                [['Type' => 'bind', 'Source' => dirname(self::keysFile()), 'Target' => '/keys', 'BindOptions' => ['CreateMountpoint' => true]]], 60);
        } catch (\Throwable $e) {
            throw new RuntimeException('Die Schlüsseldatei ließ sich nicht schreiben: '.$e->getMessage(), previous: $e);
        }
        if ($result['exit'] !== 0) {
            // the helper's log lines start with Docker's timestamp
            throw new RuntimeException('Die Schlüsseldatei ließ sich nicht schreiben: '.trim((string) preg_replace('/^\S+Z /m', '', $result['output'])));
        }
    }
}
