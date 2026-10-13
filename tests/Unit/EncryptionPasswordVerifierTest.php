<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Security\EncryptedArchive;
use Dgtlss\Capsule\Security\EncryptionPasswordVerifier;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class EncryptionPasswordVerifierTest extends TestCase
{
    public function test_public_verifier_digest_is_not_an_archive_key_for_its_salt(): void
    {
        $password = 'audit-only-dummy-password';
        $verifier = EncryptionPasswordVerifier::create($password);
        $this->assertStringStartsWith('capsule-password-verifier-v1:', $verifier);
        $fields = explode('$', substr($verifier, strpos($verifier, '$')));
        $salt = base64_decode($fields[4], true);
        $digest = base64_decode($fields[5], true);
        $this->assertSame(16, strlen($salt));
        $this->assertSame(32, strlen($digest));
        $key = (new ReflectionMethod(EncryptedArchive::class, 'deriveKey'))->invoke(null, $password, $salt);
        try {
            $this->assertFalse(hash_equals($digest, $key));
            $this->assertTrue(EncryptionPasswordVerifier::verify($verifier, $password));
        } finally {
            sodium_memzero($key);
            sodium_memzero($digest);
        }
    }

    public function test_salted_verifiers_preserve_exact_password_bytes(): void
    {
        foreach (['0', ' correct café 密碼 '] as $password) {
            $first = EncryptionPasswordVerifier::create($password);
            $second = EncryptionPasswordVerifier::create($password);
            $this->assertNotSame($first, $second);
            $this->assertTrue(EncryptionPasswordVerifier::verify($first, $password));
            $this->assertFalse(EncryptionPasswordVerifier::verify($first, $password . 'x'));
        }
    }

    public function test_unversioned_unknown_and_unbounded_verifiers_are_rejected(): void
    {
        $password = 'audit-only-dummy-password';
        $verifier = EncryptionPasswordVerifier::create($password);
        $old = sodium_crypto_pwhash_str($password, 2, 67108864);
        foreach ([null, false, [], 'invalid', $old,
            str_replace('verifier-v1:', 'verifier-v2:', $verifier),
            str_replace('m=65536', 'm=1073741824', $verifier),
            str_replace('t=2', 't=2000', $verifier),
            'capsule-password-verifier-v1:' . str_repeat('x', 1024),
        ] as $invalid) {
            $this->assertFalse(EncryptionPasswordVerifier::verify($invalid, $password));
        }
        $this->assertFalse(EncryptionPasswordVerifier::verify($verifier, ''));
    }
}
