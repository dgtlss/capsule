<?php

namespace Dgtlss\Capsule\Security;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use SensitiveParameter;

/**
 * Authenticated encryption of one bounded remote object at a time.
 *
 * Version 1 is an 8-byte header, a 24-byte random nonce, then libsodium's
 * XChaCha20-Poly1305 ciphertext and 16-byte tag. The exact object name and
 * header are authenticated as additional data. Keep metadata() with the
 * recovery information; it contains no password or encryption key.
 *
 * This class does not authenticate the completeness/order of a backup. The
 * caller must also verify its manifest and expected object list on recovery.
 */
final class EncryptedChunkCodec
{
    public const MAX_PLAINTEXT_BYTES = 67108864;
    public const OVERHEAD_BYTES = 48;

    private const HEADER = "CAPSULE\x01";
    private const MAX_OBJECT_NAME_BYTES = 4096;

    // Pin the Argon2id interactive profile in the versioned format. Changing
    // libsodium's defaults must not make an existing backup unrecoverable.
    private const OPSLIMIT = 2;
    private const MEMLIMIT = 67108864;

    private ?string $key = null;
    private array $publicMetadata;

    public function __construct(#[SensitiveParameter] ?string $password = null, ?array $metadata = null)
    {
        if ($password === null || $password === '') {
            throw new InvalidArgumentException('Encrypted streaming backups require a non-empty encryption password.');
        }

        self::assertAvailable();

        try {
            if ($metadata === null) {
                $metadata = [
                    'version' => 1,
                    'cipher' => 'xchacha20poly1305-ietf',
                    'kdf' => 'argon2id13',
                    'salt' => base64_encode(random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES)),
                    'opslimit' => self::OPSLIMIT,
                    'memlimit' => self::MEMLIMIT,
                ];
            }

            $salt = self::validateMetadata($metadata);
            $this->publicMetadata = $metadata;

            try {
                $this->key = sodium_crypto_pwhash(
                    SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
                    $password,
                    $salt,
                    self::OPSLIMIT,
                    self::MEMLIMIT,
                    SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
                );
            } catch (\Throwable) {
                // Do not attach exceptions whose arguments might contain secrets.
                throw new RuntimeException('Could not derive the encrypted backup key with Argon2id.');
            }
        } finally {
            // Erase this method's copy, without changing the caller's password.
            sodium_memzero($password);
        }
    }

    public static function fromMetadata(#[SensitiveParameter] ?string $password, array $metadata): self
    {
        try {
            return new self($password, $metadata);
        } finally {
            if ($password !== null && function_exists('sodium_memzero')) {
                sodium_memzero($password);
            }
        }
    }

    public function encrypt(#[SensitiveParameter] string $plaintext, string $objectName): string
    {
        $this->assertOpen();
        self::validateObjectName($objectName);
        if (strlen($plaintext) > self::MAX_PLAINTEXT_BYTES) {
            throw new InvalidArgumentException('Encrypted backup chunk exceeds the 64 MiB plaintext limit.');
        }

        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return self::HEADER . $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            self::additionalData($objectName),
            $nonce,
            $this->key,
        );
    }

    public function decrypt(string $ciphertext, string $objectName): string
    {
        $this->assertOpen();
        self::validateObjectName($objectName);
        $length = strlen($ciphertext);
        if ($length < self::OVERHEAD_BYTES || $length > self::MAX_PLAINTEXT_BYTES + self::OVERHEAD_BYTES) {
            throw new InvalidArgumentException('Invalid encrypted backup chunk length.');
        }
        if (substr($ciphertext, 0, strlen(self::HEADER)) !== self::HEADER) {
            throw new InvalidArgumentException('Unsupported encrypted backup chunk format.');
        }

        $nonce = substr($ciphertext, strlen(self::HEADER), SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($ciphertext, strlen(self::HEADER) + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES),
            self::additionalData($objectName),
            $nonce,
            $this->key,
        );

        if ($plaintext === false) {
            throw new RuntimeException('Encrypted backup chunk authentication failed: wrong password, object name, or damaged data.');
        }

        return $plaintext;
    }

    public function metadata(): array
    {
        return $this->publicMetadata;
    }

    /** Erase the cached per-run key. Call in the backup/recovery finally block. */
    public function close(): void
    {
        if ($this->key !== null) {
            sodium_memzero($this->key);
            $this->key = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function __debugInfo(): array
    {
        return ['metadata' => $this->publicMetadata, 'closed' => $this->key === null];
    }

    public function __serialize(): array
    {
        throw new LogicException('Encrypted backup keys cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('Encrypted backup keys cannot be unserialized.');
    }

    private function __clone()
    {
    }

    private static function assertAvailable(): void
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('Encrypted streaming backups require the PHP sodium extension with Argon2id and XChaCha20-Poly1305 support.');
        }

        foreach ([
            'sodium_crypto_pwhash',
            'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt',
            'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt',
            'sodium_memzero',
        ] as $function) {
            if (!function_exists($function)) {
                throw new RuntimeException('Encrypted streaming backups require enabled sodium Argon2id and XChaCha20-Poly1305 functions.');
            }
        }

        foreach ([
            'SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13',
            'SODIUM_CRYPTO_PWHASH_SALTBYTES',
            'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES',
            'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES',
        ] as $constant) {
            if (!defined($constant)) {
                throw new RuntimeException('Encrypted streaming backups require sodium Argon2id and XChaCha20-Poly1305 support.');
            }
        }
    }

    private static function validateMetadata(array $metadata): string
    {
        // Reject unknown fields and noncanonical values before any KDF call or
        // decoding allocation. Untrusted metadata may never choose KDF costs.
        if (count($metadata) !== 6
            || ($metadata['version'] ?? null) !== 1
            || ($metadata['cipher'] ?? null) !== 'xchacha20poly1305-ietf'
            || ($metadata['kdf'] ?? null) !== 'argon2id13'
            || ($metadata['opslimit'] ?? null) !== self::OPSLIMIT
            || ($metadata['memlimit'] ?? null) !== self::MEMLIMIT
            || !is_string($metadata['salt'] ?? null)
            || strlen($metadata['salt']) !== 24
        ) {
            throw new InvalidArgumentException('Invalid or unsupported encrypted backup metadata.');
        }

        $salt = base64_decode($metadata['salt'], true);
        if ($salt === false || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES || base64_encode($salt) !== $metadata['salt']) {
            throw new InvalidArgumentException('Invalid encrypted backup salt.');
        }

        return $salt;
    }

    private static function validateObjectName(string $objectName): void
    {
        if ($objectName === '' || strlen($objectName) > self::MAX_OBJECT_NAME_BYTES || str_contains($objectName, "\0")) {
            throw new InvalidArgumentException('Encrypted backup object name must contain 1 to 4096 bytes without null bytes.');
        }
    }

    private static function additionalData(string $objectName): string
    {
        return self::HEADER . pack('N', strlen($objectName)) . $objectName;
    }

    private function assertOpen(): void
    {
        if ($this->key === null) {
            throw new LogicException('Encrypted backup codec is closed.');
        }
    }
}
