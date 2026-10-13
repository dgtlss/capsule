<?php

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Security\EncryptedChunkCodec;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EncryptedChunkCodecTest extends TestCase
{
    private const PASSWORD = 'test fixture password, never a real credential';
    private const OBJECT_NAME = 'backups/capsule_chunk_run_000001.bin';

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('sodium') || !function_exists('sodium_crypto_pwhash') || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $this->markTestSkipped('Native sodium with Argon2id and XChaCha20-Poly1305 is required.');
        }
    }

    public function test_binary_and_empty_chunks_round_trip_after_metadata_recovery(): void
    {
        $codec = new EncryptedChunkCodec(self::PASSWORD);
        $restored = EncryptedChunkCodec::fromMetadata(self::PASSWORD, $codec->metadata());
        try {
            foreach (['', "\0\xff\xfe\x80\n", random_bytes(65537)] as $plaintext) {
                $ciphertext = $codec->encrypt($plaintext, self::OBJECT_NAME);
                $this->assertSame(strlen($plaintext) + EncryptedChunkCodec::OVERHEAD_BYTES, strlen($ciphertext));
                $this->assertSame($plaintext, $restored->decrypt($ciphertext, self::OBJECT_NAME));
            }
        } finally {
            $codec->close();
            $restored->close();
        }
    }

    public function test_wire_format_is_native_sodium_with_pinned_interactive_argon2id_parameters(): void
    {
        $codec = new EncryptedChunkCodec(self::PASSWORD);
        $metadata = $codec->metadata();
        $this->assertSame(SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, $metadata['opslimit']);
        $this->assertSame(SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, $metadata['memlimit']);
        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            self::PASSWORD,
            base64_decode($metadata['salt'], true),
            $metadata['opslimit'],
            $metadata['memlimit'],
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
        try {
            $ciphertext = $codec->encrypt('interoperable fixture', self::OBJECT_NAME);
            $this->assertSame("CAPSULE\x01", substr($ciphertext, 0, 8));
            $this->assertSame('interoperable fixture', sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($ciphertext, 32),
                "CAPSULE\x01" . pack('N', strlen(self::OBJECT_NAME)) . self::OBJECT_NAME,
                substr($ciphertext, 8, 24),
                $key,
            ));
        } finally {
            sodium_memzero($key);
            $codec->close();
        }
    }

    public function test_each_encryption_uses_a_new_nonce_and_each_run_uses_a_new_salt(): void
    {
        $codec = new EncryptedChunkCodec(self::PASSWORD);
        $nextRun = new EncryptedChunkCodec(self::PASSWORD);
        try {
            $nonces = [];
            for ($i = 0; $i < 100; $i++) {
                $nonces[] = bin2hex(substr($codec->encrypt('same plaintext', self::OBJECT_NAME), 8, 24));
            }
            $this->assertCount(100, array_unique($nonces));
            $this->assertNotSame($codec->metadata()['salt'], $nextRun->metadata()['salt']);
        } finally {
            $codec->close();
            $nextRun->close();
        }
    }

    public function test_missing_password_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('require a non-empty encryption password');
        new EncryptedChunkCodec();
    }

    public function test_empty_password_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('require a non-empty encryption password');
        new EncryptedChunkCodec('');
    }

    public function test_password_bytes_are_preserved_and_caller_password_is_not_modified(): void
    {
        $password = "0\0 fixture ";
        $codec = new EncryptedChunkCodec($password);
        $this->assertSame("0\0 fixture ", $password);
        $restored = EncryptedChunkCodec::fromMetadata($password, $codec->metadata());
        try {
            $this->assertSame("0\0 fixture ", $password);
            $this->assertSame('data', $restored->decrypt($codec->encrypt('data', self::OBJECT_NAME), self::OBJECT_NAME));
        } finally {
            $codec->close();
            $restored->close();
        }
    }

    public function test_wrong_password_fails_authentication_without_returning_plaintext_or_exposing_secrets(): void
    {
        $codec = new EncryptedChunkCodec(self::PASSWORD);
        $wrong = EncryptedChunkCodec::fromMetadata('wrong fixture password', $codec->metadata());
        try {
            $wrong->decrypt($codec->encrypt('private fixture data', self::OBJECT_NAME), self::OBJECT_NAME);
            $this->fail('Wrong passwords must not decrypt a chunk.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('authentication failed', $e->getMessage());
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            $this->assertStringNotContainsString('wrong fixture password', $e->getMessage());
            $this->assertStringNotContainsString('private fixture data', $e->getMessage());
        } finally {
            $codec->close();
            $wrong->close();
        }
    }

    #[DataProvider('tamperedChunks')]
    public function test_tampered_or_truncated_chunks_fail_closed(string $mutation): void
    {
        $codec = new EncryptedChunkCodec(self::PASSWORD);
        try {
            $ciphertext = $codec->encrypt('authenticated fixture contents', self::OBJECT_NAME);
            $ciphertext = match ($mutation) {
                'header' => 'X' . substr($ciphertext, 1),
                'version' => substr_replace($ciphertext, "\x02", 7, 1),
                'nonce' => substr_replace($ciphertext, chr(ord($ciphertext[8]) ^ 1), 8, 1),
                'payload' => substr_replace($ciphertext, chr(ord($ciphertext[32]) ^ 1), 32, 1),
                'tag' => substr($ciphertext, 0, -1) . chr(ord(substr($ciphertext, -1)) ^ 1),
                'truncated-tag' => substr($ciphertext, 0, -1),
                'truncated-header' => substr($ciphertext, 0, 7),
                'truncated-nonce' => substr($ciphertext, 0, 31),
                'empty' => '',
                'appended' => $ciphertext . 'extra',
            };
            $this->expectException(\Exception::class);
            $codec->decrypt($ciphertext, self::OBJECT_NAME);
        } finally {
            $codec->close();
        }
    }

    public static function tamperedChunks(): array
    {
        return array_combine(
            $cases = ['header', 'version', 'nonce', 'payload', 'tag', 'truncated-tag', 'truncated-header', 'truncated-nonce', 'empty', 'appended'],
            array_map(fn ($case) => [$case], $cases),
        );
    }

    public function test_object_name_is_authenticated(): void
    {
        $codec = new EncryptedChunkCodec(self::PASSWORD);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('authentication failed');
            $codec->decrypt($codec->encrypt('contents', self::OBJECT_NAME), self::OBJECT_NAME . '-reordered');
        } finally {
            $codec->close();
        }
    }

    public function test_a_valid_but_changed_salt_cannot_authenticate_old_chunks(): void
    {
        $codec = new EncryptedChunkCodec(self::PASSWORD);
        $metadata = $codec->metadata();
        $salt = base64_decode($metadata['salt'], true);
        $salt[0] = chr(ord($salt[0]) ^ 1);
        $metadata['salt'] = base64_encode($salt);
        $wrong = EncryptedChunkCodec::fromMetadata(self::PASSWORD, $metadata);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('authentication failed');
            $wrong->decrypt($codec->encrypt('contents', self::OBJECT_NAME), self::OBJECT_NAME);
        } finally {
            $codec->close();
            $wrong->close();
        }
    }

    #[DataProvider('invalidMetadata')]
    public function test_metadata_is_strictly_validated_before_key_derivation(array $changes): void
    {
        $metadata = array_replace([
            'version' => 1,
            'cipher' => 'xchacha20poly1305-ietf',
            'kdf' => 'argon2id13',
            'salt' => base64_encode(str_repeat("\0", 16)),
            'opslimit' => 2,
            'memlimit' => 67108864,
        ], $changes);
        $this->expectException(InvalidArgumentException::class);
        EncryptedChunkCodec::fromMetadata(self::PASSWORD, $metadata);
    }

    public static function invalidMetadata(): array
    {
        return [
            'unsupported version' => [['version' => 2]],
            'string version' => [['version' => '1']],
            'unknown cipher' => [['cipher' => 'aes-256-cbc']],
            'unknown kdf' => [['kdf' => 'argon2i13']],
            'unbounded memory' => [['memlimit' => PHP_INT_MAX]],
            'unbounded work' => [['opslimit' => PHP_INT_MAX]],
            'weaker memory' => [['memlimit' => 8192]],
            'weaker work' => [['opslimit' => 1]],
            'string memory' => [['memlimit' => '67108864']],
            'float work' => [['opslimit' => 2.0]],
            'unknown field' => [['password' => 'never accept secrets in metadata']],
            'missing salt' => [['salt' => null]],
            'array salt' => [['salt' => []]],
            'long salt' => [['salt' => str_repeat('A', 4096)]],
            'short salt' => [['salt' => 'AA==']],
            'wrong decoded salt length' => [['salt' => str_repeat('A', 24)]],
            'invalid base64' => [['salt' => str_repeat('!', 24)]],
            'noncanonical base64' => [['salt' => 'AAAAAAAAAAAAAAAAAAAAAB==']],
        ];
    }

    public function test_missing_metadata_fields_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EncryptedChunkCodec::fromMetadata(self::PASSWORD, []);
    }

    public function test_metadata_and_debug_output_do_not_expose_password_or_key(): void
    {
        $codec = new EncryptedChunkCodec(self::PASSWORD);
        $keyProperty = new \ReflectionProperty($codec, 'key');
        $key = $keyProperty->getValue($codec);
        try {
            $metadata = $codec->metadata();
            $this->assertSame(['version', 'cipher', 'kdf', 'salt', 'opslimit', 'memlimit'], array_keys($metadata));
            $json = json_encode($metadata, JSON_THROW_ON_ERROR);
            ob_start();
            var_dump($codec);
            $debug = ob_get_clean();
            foreach ([$json, $debug] as $output) {
                $this->assertStringNotContainsString(self::PASSWORD, $output);
                $this->assertStringNotContainsString($key, $output);
                $this->assertStringNotContainsString(bin2hex($key), $output);
                $this->assertStringNotContainsString(base64_encode($key), $output);
            }
            $metadata['salt'] = 'changed locally';
            $this->assertNotSame($metadata, $codec->metadata());
        } finally {
            sodium_memzero($key);
            $codec->close();
        }
    }

    public function test_serialization_cannot_persist_cached_keys(): void
    {
        $codec = new EncryptedChunkCodec(self::PASSWORD);
        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('cannot be serialized');
            serialize($codec);
        } finally {
            $codec->close();
        }
    }

    public function test_cloning_cannot_copy_cached_keys(): void
    {
        $codec = new EncryptedChunkCodec(self::PASSWORD);
        try {
            $this->expectException(\Error::class);
            $copy = clone $codec;
        } finally {
            $codec->close();
        }
    }

    public function test_key_is_cached_during_use_and_cleared_by_idempotent_close(): void
    {
        $codec = new EncryptedChunkCodec(self::PASSWORD);
        $property = new \ReflectionProperty($codec, 'key');
        $initialKey = $property->getValue($codec);
        $ciphertext = $codec->encrypt('contents', self::OBJECT_NAME);
        $this->assertSame('contents', $codec->decrypt($ciphertext, self::OBJECT_NAME));
        $this->assertSame($initialKey, $property->getValue($codec));
        sodium_memzero($initialKey);
        $metadata = $codec->metadata();
        $codec->close();
        $codec->close();
        $this->assertNull($property->getValue($codec));
        $this->assertSame($metadata, $codec->metadata());
        foreach (['encrypt', 'decrypt'] as $method) {
            try {
                $codec->$method($ciphertext, self::OBJECT_NAME);
                $this->fail('A closed codec must not be reused.');
            } catch (LogicException $e) {
                $this->assertSame('Encrypted backup codec is closed.', $e->getMessage());
            }
        }
    }

    public function test_destructor_clears_key(): void
    {
        $codec = new EncryptedChunkCodec(self::PASSWORD);
        $codec->__destruct();
        $this->assertNull((new \ReflectionProperty($codec, 'key'))->getValue($codec));
    }

    public function test_exactly_the_maximum_chunk_size_is_supported(): void
    {
        $codec = new EncryptedChunkCodec(self::PASSWORD);
        try {
            $plaintext = str_repeat('x', EncryptedChunkCodec::MAX_PLAINTEXT_BYTES);
            $ciphertext = $codec->encrypt($plaintext, self::OBJECT_NAME);
            $this->assertSame(EncryptedChunkCodec::MAX_PLAINTEXT_BYTES + EncryptedChunkCodec::OVERHEAD_BYTES, strlen($ciphertext));
            $this->assertSame($plaintext, $codec->decrypt($ciphertext, self::OBJECT_NAME));
        } finally {
            $codec->close();
        }
    }

    public function test_oversized_plaintext_is_rejected(): void
    {
        $codec = new EncryptedChunkCodec(self::PASSWORD);
        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('64 MiB plaintext limit');
            $codec->encrypt(str_repeat('x', EncryptedChunkCodec::MAX_PLAINTEXT_BYTES + 1), self::OBJECT_NAME);
        } finally {
            $codec->close();
        }
    }

    public function test_oversized_ciphertext_is_rejected_before_parsing_or_decrypting(): void
    {
        $codec = new EncryptedChunkCodec(self::PASSWORD);
        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('chunk length');
            $codec->decrypt(str_repeat('x', EncryptedChunkCodec::MAX_PLAINTEXT_BYTES + EncryptedChunkCodec::OVERHEAD_BYTES + 1), self::OBJECT_NAME);
        } finally {
            $codec->close();
        }
    }

    #[DataProvider('invalidObjectNames')]
    public function test_invalid_object_names_are_rejected(string $name): void
    {
        $codec = new EncryptedChunkCodec(self::PASSWORD);
        try {
            foreach (['encrypt', 'decrypt'] as $method) {
                try {
                    $codec->$method('contents', $name);
                    $this->fail('Invalid object names must be rejected.');
                } catch (InvalidArgumentException $e) {
                    $this->assertStringContainsString('object name', $e->getMessage());
                }
            }
        } finally {
            $codec->close();
        }
    }

    public static function invalidObjectNames(): array
    {
        return ['empty' => [''], 'too long' => [str_repeat('a', 4097)], 'null byte' => ["object\0name"]];
    }

    public function test_missing_native_sodium_has_a_clear_failure(): void
    {
        $this->assertUnsupportedRuntime(['-n'], 'require the PHP sodium extension', true);
    }

    public function test_disabled_sodium_function_has_a_clear_failure(): void
    {
        $options = ['-d', 'disable_functions=sodium_crypto_pwhash'];
        if ($ini = php_ini_loaded_file()) {
            $options = array_merge(['-c', $ini], $options);
        }
        $this->assertUnsupportedRuntime($options, 'require enabled sodium');
    }

    private function assertUnsupportedRuntime(array $options, string $expected, bool $withoutExtension = false): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is required to test unavailable sodium safely in an isolated runtime.');
        }
        $file = dirname(__DIR__, 2) . '/src/Security/EncryptedChunkCodec.php';
        $script = ($withoutExtension ? 'if (extension_loaded("sodium")) { exit(77); }' : '')
            . 'require ' . var_export($file, true) . ';'
            . 'try { new \\Dgtlss\\Capsule\\Security\\EncryptedChunkCodec("test-only fixture"); exit(1); }'
            . 'catch (\\RuntimeException $e) { echo $e->getMessage(); }';
        $process = proc_open(array_merge([PHP_BINARY], $options, ['-r', $script]), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($withoutExtension && $exitCode === 77) {
            $this->markTestSkipped('Sodium is compiled into this PHP binary and cannot be unloaded with -n.');
        }
        $this->assertSame(0, $exitCode, $stderr);
        $this->assertStringContainsString($expected, $stdout);
        $this->assertStringNotContainsString('test-only fixture', $stdout);
    }
}
