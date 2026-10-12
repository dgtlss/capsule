<?php

declare(strict_types=1);

namespace Dgtlss\Capsule\Security;

use Generator;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Capsule's versioned, streaming encrypted archive container.
 *
 * Version 1 header (64 bytes, all authenticated as AD on EVERY message):
 *   0..7   "CAPSENC\0" magic
 *   8..11  version=1, KDF=1 (Argon2id13), cipher=1 (secretstream), reserved=0
 *  12..23  big-endian uint32 ops=2, memory=67108864, plaintext frame=65536
 *  24..39  random 16-byte Argon2id salt
 *  40..63  native 24-byte XChaCha20-Poly1305 secretstream header
 *
 * Then: uint32 big-endian ciphertext length followed by that many bytes.
 * Nonempty data frames have TAG_MESSAGE and exactly 65536 plaintext bytes,
 * except the last data frame, which may be shorter. A separate empty frame
 * with TAG_FINAL is mandatory, followed by physical EOF. No other tags or
 * profiles are accepted. Ciphertext frame lengths are bounded to 17..65553.
 *
 * @internal One instance belongs to ONE immutable, fully verified archive.
 * Replaying encrypt() intentionally repeats its original secretstream state
 * for upload retries. The caller MUST authenticate and verify its frozen
 * input before yielding plaintext, and MUST keep the archive plan unchanged.
 * Never use this instance for different input: create a fresh instance with
 * a new random salt/header instead. Arbitrary mutable sources are unsafe.
 */
final class EncryptedArchive
{
    public const FORMAT = 'capsule-secretstream-v1';
    public const EXTENSION = '.capsule.enc';

    private const MAGIC = "CAPSENC\0";
    private const HEADER_BYTES = 64;
    private const FRAME_BYTES = 65536;
    private const OVERHEAD_BYTES = 17;
    private const OPSLIMIT = 2;
    private const MEMLIMIT = 67108864;

    private ?string $key = null;
    private ?string $initialState = null;
    private ?string $activeState = null;
    private string $header = '';
    private bool $active = false;

    public function __construct(#[SensitiveParameter] string $password)
    {
        try {
            self::validatePassword($password);
            $salt = random_bytes(16);
            $this->key = self::deriveKey($password, $salt);

            try {
                [$this->initialState, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key);
            } catch (Throwable) {
                $this->close();
                throw new RuntimeException('Could not initialize encrypted archive secretstream.');
            }

            $this->header = self::profile() . $salt . $streamHeader;
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($password);
            }
        }
    }

    /** Validate before reading sources or making storage changes. */
    public static function validatePassword(#[SensitiveParameter] ?string $password): void
    {
        if ($password === null || $password === '') {
            throw new InvalidArgumentException('Encrypted archives require a non-empty encryption password.');
        }
        if (strlen($password) > 4096) {
            throw new InvalidArgumentException('Encrypted archive passwords must contain 1 to 4096 bytes.');
        }
        if (str_contains($password, "\0")) {
            throw new InvalidArgumentException('Encrypted archive passwords cannot contain NUL bytes.');
        }
        if (str_contains($password, "\r") || str_contains($password, "\n")) {
            throw new InvalidArgumentException('Encrypted archive passwords must be a single line without CR or LF bytes.');
        }

        self::assertAvailable();
    }

    public static function assertAvailable(): void
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('Encrypted archives require the native PHP sodium extension with Argon2id and secretstream support.');
        }

        foreach ([
            'sodium_crypto_pwhash',
            'sodium_crypto_secretstream_xchacha20poly1305_init_push',
            'sodium_crypto_secretstream_xchacha20poly1305_push',
            'sodium_crypto_secretstream_xchacha20poly1305_init_pull',
            'sodium_crypto_secretstream_xchacha20poly1305_pull',
            'sodium_memzero',
        ] as $function) {
            if (!function_exists($function)) {
                throw new RuntimeException('Encrypted archives require enabled native sodium Argon2id and secretstream functions.');
            }
        }

        foreach ([
            'SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13' => 2,
            'SODIUM_CRYPTO_PWHASH_SALTBYTES' => 16,
            'SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES' => 32,
            'SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES' => 24,
            'SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES' => self::OVERHEAD_BYTES,
            'SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE' => 0,
            'SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL' => 3,
        ] as $constant => $expected) {
            if (!defined($constant) || constant($constant) !== $expected) {
                throw new RuntimeException('Encrypted archives require compatible native sodium Argon2id and secretstream support.');
            }
        }
    }

    /**
     * @param iterable<string> $plaintext Immutable, authenticated source bytes.
     * @return Generator<int, string> Header and bounded length-prefixed frames.
     */
    public function encrypt(#[SensitiveParameter] iterable $plaintext): Generator
    {
        $this->assertOpen();
        if ($this->active) {
            throw new LogicException('Encrypted archive generation cannot run concurrently.');
        }

        $this->active = true;
        $this->activeState = $this->initialState;
        $pending = '';

        try {
            yield $this->header;

            foreach ($plaintext as $bytes) {
                $this->assertOpen();
                if (!is_string($bytes)) {
                    throw new InvalidArgumentException('Encrypted archive input must contain only strings.');
                }

                // Never concatenate an unbounded producer chunk into pending.
                // Frame boundaries depend on bytes, not producer chunk sizes.
                $length = strlen($bytes);
                for ($offset = 0; $offset < $length;) {
                    $take = min(self::FRAME_BYTES - strlen($pending), $length - $offset);
                    $pending .= substr($bytes, $offset, $take);
                    $offset += $take;

                    if (strlen($pending) === self::FRAME_BYTES) {
                        $frame = $this->push($pending, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
                        sodium_memzero($pending);
                        $pending = '';
                        yield $frame;
                    }
                }
            }

            if ($pending !== '') {
                $frame = $this->push($pending, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
                sodium_memzero($pending);
                $pending = '';
                yield $frame;
            }

            yield $this->push('', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        } finally {
            sodium_memzero($pending);
            self::erase($this->activeState);
            $this->active = false;
        }
    }

    /**
     * Decrypt into CALLER-OWNED PRIVATE STAGING, never a live restore target.
     *
     * Output is incomplete/untrusted until this method returns successfully:
     * per-frame authentication alone does not prove whole-file completeness.
     * The caller must discard staging on ANY exception, and expose or extract
     * it only after the authenticated empty FINAL frame and EOF are verified.
     * This method neither closes nor rewinds the caller's stream resources.
     *
     * @param resource $inputResource Readable, blocking ciphertext stream.
     * @param resource $outputResource Writable private staging stream.
     */
    public static function decrypt($inputResource, $outputResource, #[SensitiveParameter] ?string $password): void
    {
        $key = null;
        $state = null;
        $plaintext = null;

        try {
            self::validatePassword($password);
            self::assertStream($inputResource);
            self::assertStream($outputResource);
            if ($inputResource === $outputResource) {
                throw new InvalidArgumentException('Encrypted archive input and private staging streams must be different.');
            }

            $header = self::readExact($inputResource, self::HEADER_BYTES);
            // Compare every fixed profile byte BEFORE KDF or frame allocation.
            // A hostile file can never select work, memory, or frame limits.
            if (substr($header, 0, 24) !== self::profile()) {
                throw new InvalidArgumentException('Invalid or unsupported encrypted archive header.');
            }

            $key = self::deriveKey($password, substr($header, 24, 16));
            try {
                $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($header, 40, 24), $key);
            } catch (Throwable) {
                throw new RuntimeException('Could not initialize encrypted archive decryption.');
            }
            self::erase($key);
            $shortDataFrame = false;

            while (true) {
                $length = unpack('Nlength', self::readExact($inputResource, 4))['length'];
                if ($length < self::OVERHEAD_BYTES || $length > self::FRAME_BYTES + self::OVERHEAD_BYTES) {
                    throw new InvalidArgumentException('Invalid encrypted archive frame length.');
                }

                $ciphertext = self::readExact($inputResource, $length);
                try {
                    $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ciphertext, $header);
                } catch (Throwable) {
                    throw new RuntimeException('Encrypted archive authentication failed: wrong password or damaged data.');
                }
                if ($result === false) {
                    throw new RuntimeException('Encrypted archive authentication failed: wrong password or damaged data.');
                }

                [$plaintext, $tag] = $result;
                unset($result);
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    if ($plaintext !== '') {
                        throw new InvalidArgumentException('Encrypted archive final frame must be empty.');
                    }
                    $extra = @fread($inputResource, 1);
                    if ($extra === false || $extra !== '' || !feof($inputResource)) {
                        throw new RuntimeException('Encrypted archive must end immediately after its final frame.');
                    }

                    return;
                }

                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE || $plaintext === '' || $shortDataFrame) {
                    throw new InvalidArgumentException('Invalid encrypted archive data frame or tag.');
                }
                $shortDataFrame = strlen($plaintext) < self::FRAME_BYTES;
                self::writeAll($outputResource, $plaintext);
                self::erase($plaintext);
            }
        } finally {
            if (function_exists('sodium_memzero')) {
                self::erase($password);
                self::erase($key);
                self::erase($state);
                self::erase($plaintext);
            }
        }
    }

    /** Magic detection only; decrypt() performs full validation/authentication. */
    public static function isEncryptedFile(string $path): bool
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return false;
        }

        try {
            return @fread($stream, strlen(self::MAGIC)) === self::MAGIC;
        } finally {
            fclose($stream);
        }
    }

    /** Erase keys and both initial/replay state, including a suspended replay. */
    public function close(): void
    {
        self::erase($this->key);
        self::erase($this->initialState);
        self::erase($this->activeState);
    }

    public function __destruct()
    {
        $this->close();
    }

    public function __debugInfo(): array
    {
        return ['format' => self::FORMAT, 'closed' => $this->initialState === null];
    }

    public function __serialize(): array
    {
        throw new LogicException('Encrypted archive keys and state cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('Encrypted archive keys and state cannot be unserialized.');
    }

    private function __clone(): void
    {
    }

    private static function profile(): string
    {
        return self::MAGIC . "\x01\x01\x01\x00" . pack('NNN', self::OPSLIMIT, self::MEMLIMIT, self::FRAME_BYTES);
    }

    private static function deriveKey(#[SensitiveParameter] string $password, string $salt): string
    {
        try {
            return sodium_crypto_pwhash(32, $password, $salt, self::OPSLIMIT, self::MEMLIMIT, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        } catch (Throwable) {
            // Do not chain native errors that could expose password/key args.
            throw new RuntimeException('Could not derive the encrypted archive key with Argon2id.');
        } finally {
            sodium_memzero($password);
        }
    }

    private function push(#[SensitiveParameter] string $plaintext, int $tag): string
    {
        $this->assertOpen();
        try {
            $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push($this->activeState, $plaintext, $this->header, $tag);
        } catch (Throwable) {
            throw new RuntimeException('Could not encrypt archive frame.');
        }

        return pack('N', strlen($ciphertext)) . $ciphertext;
    }

    private function assertOpen(): void
    {
        if ($this->initialState === null) {
            throw new LogicException('Encrypted archive has been closed.');
        }
    }

    private static function erase(#[SensitiveParameter] ?string &$secret): void
    {
        if ($secret !== null) {
            sodium_memzero($secret);
            $secret = null;
        }
    }

    private static function assertStream($stream): void
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('Encrypted archive input and output must be open stream resources.');
        }
    }

    /** Read no more than one bounded field/frame, tolerating short reads. */
    private static function readExact($stream, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $part = @fread($stream, $length - strlen($bytes));
            if ($part === false || $part === '') {
                throw new RuntimeException('Encrypted archive is truncated or could not be read; authenticated final frame and EOF are required.');
            }
            $bytes .= $part;
        }

        return $bytes;
    }

    private static function writeAll($stream, #[SensitiveParameter] string $bytes): void
    {
        for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += $written) {
            $written = @fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write decrypted archive to private staging.');
            }
        }
    }
}
