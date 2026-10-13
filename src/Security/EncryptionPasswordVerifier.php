<?php

declare(strict_types=1);

namespace Dgtlss\Capsule\Security;

use RuntimeException;
use SensitiveParameter;
use SodiumException;
use Throwable;

/** Password compatibility metadata, separate from archive and chunk keys. */
final class EncryptionPasswordVerifier
{
    private const PREFIX = 'capsule-password-verifier-v1:';
    private const DOMAIN = "capsule.password-verifier.v1\0";
    private const OPSLIMIT = 2;
    private const MEMLIMIT = 67108864;
    private const MAX_HASH_BYTES = 128;

    public static function create(#[SensitiveParameter] string $password): string
    {
        // A public verifier digest must never be usable as an archive/chunk key.
        $input = self::DOMAIN . $password;
        try {
            return self::PREFIX . sodium_crypto_pwhash_str($input, self::OPSLIMIT, self::MEMLIMIT);
        } catch (Throwable) {
            throw new RuntimeException('Could not create the backup password verifier.');
        } finally {
            sodium_memzero($input);
            sodium_memzero($password);
        }
    }

    public static function verify(mixed $verifier, #[SensitiveParameter] string $password): bool
    {
        $input = null;
        try {
            if (!is_string($verifier) || $password === ''
                || strlen($verifier) > strlen(self::PREFIX) + self::MAX_HASH_BYTES
                || !str_starts_with($verifier, self::PREFIX)) {
                return false;
            }
            $hash = substr($verifier, strlen(self::PREFIX));
            if (sodium_crypto_pwhash_str_needs_rehash($hash, self::OPSLIMIT, self::MEMLIMIT)) {
                return false;
            }
            $input = self::DOMAIN . $password;
            return sodium_crypto_pwhash_str_verify($hash, $input);
        } catch (SodiumException) {
            return false;
        } finally {
            if ($input !== null) {
                sodium_memzero($input);
            }
            sodium_memzero($password);
        }
    }
}
