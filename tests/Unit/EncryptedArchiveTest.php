<?php

declare(strict_types=1);

namespace Dgtlss\Capsule\Tests\Unit;

use Dgtlss\Capsule\Security\EncryptedArchive;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EncryptedArchiveTest extends TestCase
{
    private const PASSWORD = 'fixture-only archive password';
    private const HEADER_BYTES = 64;
    private const FRAME_BYTES = 65536;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('sodium') || !function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            $this->markTestSkipped('Native sodium with Argon2id and secretstream is required.');
        }
    }

    #[DataProvider('plaintexts')]
    public function test_empty_binary_and_multiframe_plaintext_round_trip(string $plaintext): void
    {
        $ciphertext = $this->encrypt([$plaintext]);
        $this->assertSame($plaintext, $this->decrypt($ciphertext));
        $frames = $this->frames($ciphertext);
        $this->assertCount((int) ceil(strlen($plaintext) / self::FRAME_BYTES) + 1, $frames);
        $this->assertSame(21, strlen(end($frames)), 'The final frame always contains empty plaintext.');
        foreach ($frames as $frame) {
            $this->assertLessThanOrEqual(65557, strlen($frame));
        }
    }

    public static function plaintexts(): array
    {
        return [
            'empty' => [''],
            'binary' => ["\0\xff\xfe\x80hello\n"],
            'one exact frame' => [str_repeat('x', self::FRAME_BYTES)],
            'one plus one byte' => [str_repeat('y', self::FRAME_BYTES + 1)],
            'multiple frames' => [str_repeat('abc', self::FRAME_BYTES)],
        ];
    }

    public function test_wire_format_is_readable_by_native_sodium_with_the_pinned_profile(): void
    {
        $plaintext = str_repeat("\x00native interoperation\xff", 7000);
        $ciphertext = $this->encrypt([$plaintext]);
        $header = substr($ciphertext, 0, self::HEADER_BYTES);
        $this->assertSame("CAPSENC\0\x01\x01\x01\x00", substr($header, 0, 12));
        $this->assertSame(['ops' => 2, 'memory' => 67108864, 'frame' => 65536], unpack('Nops/Nmemory/Nframe', substr($header, 12, 12)));
        $this->assertSame('capsule-secretstream-v1', EncryptedArchive::FORMAT);
        $this->assertSame('.capsule.enc', EncryptedArchive::EXTENSION);
        $key = sodium_crypto_pwhash(32, self::PASSWORD, substr($header, 24, 16), 2, 67108864, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($header, 40, 24), $key);
        $restored = '';
        try {
            $frames = $this->frames($ciphertext);
            foreach ($frames as $i => $frame) {
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, substr($frame, 4), $header);
                $this->assertNotFalse($result);
                [$bytes, $tag] = $result;
                $this->assertSame($i === count($frames) - 1 ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE, $tag);
                $restored .= $bytes;
            }
            $this->assertSame($plaintext, $restored);
        } finally {
            sodium_memzero($key);
            sodium_memzero($state);
        }
    }

    public function test_identical_immutable_input_replays_identically_regardless_of_producer_boundaries(): void
    {
        $archive = new EncryptedArchive(self::PASSWORD);
        $plaintext = str_repeat('immutable replay contents', 13000);
        try {
            $first = implode('', iterator_to_array($archive->encrypt([$plaintext])));
            $replay = implode('', iterator_to_array($archive->encrypt(str_split($plaintext, 3077))));
            $withEmptyChunks = implode('', iterator_to_array($archive->encrypt(['', substr($plaintext, 0, 1), '', substr($plaintext, 1), ''])));
            $this->assertSame($first, $replay);
            $this->assertSame($first, $withEmptyChunks);
            $this->assertSame($plaintext, $this->decrypt($replay));

            $newRun = $this->encrypt([$plaintext]);
            $this->assertNotSame($first, $newRun);
            $this->assertNotSame(substr($first, 24, 16), substr($newRun, 24, 16));
            $this->assertNotSame(substr($first, 40, 24), substr($newRun, 40, 24));
        } finally {
            $archive->close();
        }
    }

    public function test_interrupted_generation_can_replay_the_same_immutable_source(): void
    {
        $archive = new EncryptedArchive(self::PASSWORD);
        $source = [str_repeat('frozen', 50000)];
        try {
            $interrupted = $archive->encrypt($source);
            $prefix = $interrupted->current();
            $interrupted->next();
            $prefix .= $interrupted->current();
            unset($interrupted);
            $replay = implode('', iterator_to_array($archive->encrypt($source)));
            $this->assertStringStartsWith($prefix, $replay);
            $this->assertSame($source[0], $this->decrypt($replay));
        } finally {
            $archive->close();
        }
    }

    #[DataProvider('missingPasswords')]
    public function test_missing_password_fails_preflight_and_decryption(?string $password): void
    {
        $input = fopen('php://memory', 'w+b');
        $output = fopen('php://memory', 'w+b');
        try {
            try {
                EncryptedArchive::validatePassword($password);
                $this->fail('Missing passwords must fail preflight.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('non-empty encryption password', $e->getMessage());
            }
            $this->expectException(InvalidArgumentException::class);
            EncryptedArchive::decrypt($input, $output, $password);
        } finally {
            $this->assertSame(0, ftell($input));
            $this->assertSame(0, ftell($output));
            fclose($input);
            fclose($output);
        }
    }

    public static function missingPasswords(): array
    {
        return ['missing' => [null], 'empty' => ['']];
    }

    public function test_empty_password_is_rejected_by_constructor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EncryptedArchive('');
    }

    public function test_long_passwords_are_not_trimmed_or_truncated_and_caller_is_unchanged(): void
    {
        $password = '0 whitespace ' . str_repeat('long password ', 20) . ' ';
        $expected = $password;
        $archive = new EncryptedArchive($password);
        try {
            $ciphertext = implode('', iterator_to_array($archive->encrypt(['private payload'])));
            $this->assertSame($expected, $password);
            $this->assertSame('private payload', $this->decrypt($ciphertext, $password));
            $this->assertSame($expected, $password);
            $this->expectException(RuntimeException::class);
            $this->decrypt($ciphertext, substr($password, 0, 99));
        } finally {
            $archive->close();
        }
    }

    #[DataProvider('validPasswordBoundaries')]
    public function test_password_boundaries_accept_exact_bytes(string $password): void
    {
        EncryptedArchive::validatePassword($password);
        $archive = new EncryptedArchive($password);
        try {
            $ciphertext = implode('', iterator_to_array($archive->encrypt(['payload'])));
            $this->assertSame('payload', $this->decrypt($ciphertext, $password));
        } finally {
            $archive->close();
        }
    }

    public static function validPasswordBoundaries(): array
    {
        return ['one byte' => ['0'], '4096 bytes' => [str_repeat('x', 4096)], 'utf8' => [' correct café 密碼 ']];
    }

    #[DataProvider('invalidPasswords')]
    public function test_invalid_passwords_fail_before_encryption(string $password, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new EncryptedArchive($password);
    }

    public static function invalidPasswords(): array
    {
        return [
            '4097 bytes' => [str_repeat('x', 4097), '1 to 4096 bytes'],
            'NUL' => ["secret\0hidden", 'NUL bytes'],
            'CR' => ["secret\rhidden", 'single line'],
            'LF' => ["secret\nhidden", 'single line'],
            'CRLF' => ["secret\r\n", 'single line'],
        ];
    }

    public function test_wrong_password_authenticates_nothing_and_does_not_leak_secrets_in_traces(): void
    {
        $ciphertext = $this->encrypt(['private payload']);
        $input = $this->stream($ciphertext);
        $output = fopen('php://memory', 'w+b');
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                EncryptedArchive::decrypt($input, $output, 'wrong fixture secret');
                $this->fail('Wrong password must fail.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('authentication failed', $e->getMessage());
                $this->assertNull($e->getPrevious());
                $this->assertSame(0, ftell($output));
                foreach ([self::PASSWORD, 'wrong fixture secret', 'private payload'] as $secret) {
                    $this->assertStringNotContainsString($secret, (string) $e);
                    $this->assertStringNotContainsString($secret, print_r($e->getTrace(), true));
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $old);
            fclose($input);
            fclose($output);
        }
    }

    public function test_every_header_byte_is_validated_or_authenticated(): void
    {
        $ciphertext = $this->encrypt(['private payload']);
        for ($offset = 0; $offset < self::HEADER_BYTES; $offset++) {
            $changed = $ciphertext;
            $changed[$offset] = chr(ord($changed[$offset]) ^ 1);
            try {
                $this->decrypt($changed);
                $this->fail('Header byte ' . $offset . ' was not protected.');
            } catch (InvalidArgumentException|RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    #[DataProvider('malformedArchives')]
    public function test_corruption_truncation_reordering_and_trailing_data_fail_closed(string $mutation): void
    {
        $ciphertext = $this->encrypt([str_repeat('a', self::FRAME_BYTES), str_repeat('b', self::FRAME_BYTES), 'tail']);
        $header = substr($ciphertext, 0, self::HEADER_BYTES);
        $frames = $this->frames($ciphertext);
        $changed = match ($mutation) {
            'empty' => '',
            'short magic' => substr($ciphertext, 0, 7),
            'short header' => substr($ciphertext, 0, 63),
            'header only' => $header,
            'short length' => $header . "\x00\x01",
            'zero length' => $header . pack('N', 0),
            'small length' => $header . pack('N', 16),
            'large length' => $header . pack('N', 65554),
            'hostile length' => $header . pack('N', 0xffffffff),
            'changed bounded length' => $header . pack('N', 65552) . substr($ciphertext, 68),
            'short ciphertext' => substr($ciphertext, 0, 100),
            'payload bit' => substr_replace($ciphertext, chr(ord($ciphertext[69]) ^ 1), 69, 1),
            'authentication bit' => substr_replace($ciphertext, chr(ord($ciphertext[65556]) ^ 1), 65556, 1),
            'truncated final' => substr($ciphertext, 0, -1),
            'missing final' => $header . implode('', array_slice($frames, 0, -1)),
            'removed frame' => $header . $frames[0] . $frames[2] . $frames[3],
            'reordered frames' => $header . $frames[1] . $frames[0] . $frames[2] . $frames[3],
            'duplicated frame' => $header . $frames[0] . $frames[0] . implode('', array_slice($frames, 1)),
            'appended byte' => $ciphertext . "\0",
            'appended final' => $ciphertext . $frames[3],
            'concatenated archive' => $ciphertext . $ciphertext,
        };
        $this->expectException(\Exception::class);
        $this->decrypt($changed);
    }

    public static function malformedArchives(): array
    {
        $cases = ['empty', 'short magic', 'short header', 'header only', 'short length', 'zero length', 'small length', 'large length', 'hostile length', 'changed bounded length', 'short ciphertext', 'payload bit', 'authentication bit', 'truncated final', 'missing final', 'removed frame', 'reordered frames', 'duplicated frame', 'appended byte', 'appended final', 'concatenated archive'];

        return array_combine($cases, array_map(fn ($case) => [$case], $cases));
    }

    #[DataProvider('hostileProfiles')]
    public function test_hostile_costs_and_profiles_are_rejected_before_attempting_key_derivation(int $offset, string $value): void
    {
        // No KDF runs for this header: there is no key or authentic ciphertext.
        $header = $this->profile() . str_repeat("\0", 40);
        $header = substr_replace($header, $value, $offset, strlen($value));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('header');
        $this->decrypt($header);
    }

    public static function hostileProfiles(): array
    {
        return [
            'version' => [8, "\x02"],
            'kdf' => [9, "\x02"],
            'cipher' => [10, "\x02"],
            'reserved' => [11, "\x01"],
            'excessive work' => [12, pack('N', 0xffffffff)],
            'excessive memory' => [16, pack('N', 0xffffffff)],
            'excessive frame' => [20, pack('N', 0xffffffff)],
            'weak work' => [12, pack('N', 1)],
            'weak memory' => [16, pack('N', 8192)],
            'noncanonical frame' => [20, pack('N', 32768)],
        ];
    }

    #[DataProvider('invalidNativeMessages')]
    public function test_authenticated_but_noncanonical_tags_or_frames_are_rejected(array $messages): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->decrypt($this->nativeArchive($messages));
    }

    public static function invalidNativeMessages(): array
    {
        return [
            'push tag' => [[['data', 1], ['', 3]]],
            'rekey tag' => [[['data', 2], ['', 3]]],
            'unsupported tag' => [[['data', 4], ['', 3]]],
            'empty data' => [[['', 0], ['', 3]]],
            'nonempty final' => [[['data', 3]]],
            'data after short frame' => [[['short', 0], ['more', 0], ['', 3]]],
        ];
    }

    public function test_native_sodium_fixture_can_be_decrypted_and_header_ad_is_required_on_every_frame(): void
    {
        $messages = [[str_repeat('x', self::FRAME_BYTES), 0], ['last', 0], ['', 3]];
        $this->assertSame(str_repeat('x', self::FRAME_BYTES) . 'last', $this->decrypt($this->nativeArchive($messages)));
        foreach ([0, 1, 2] as $wrongAdFrame) {
            try {
                $this->decrypt($this->nativeArchive($messages, $wrongAdFrame));
                $this->fail('Every message must bind the entire header.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('authentication failed', $e->getMessage());
            }
        }
    }

    public function test_magic_detection_recognizes_encrypted_files_but_does_not_claim_authentication(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'capsule-format-');
        try {
            file_put_contents($file, $this->encrypt(['payload']));
            $this->assertTrue(EncryptedArchive::isEncryptedFile($file));
            file_put_contents($file, "CAPSENC\0");
            $this->assertTrue(EncryptedArchive::isEncryptedFile($file));
            file_put_contents($file, "PK\x03\x04ordinary zip");
            $this->assertFalse(EncryptedArchive::isEncryptedFile($file));
            file_put_contents($file, 'CAPS');
            $this->assertFalse(EncryptedArchive::isEncryptedFile($file));
            $this->assertFalse(EncryptedArchive::isEncryptedFile($file . '-missing'));
        } finally {
            unlink($file);
        }
    }

    public function test_keys_and_states_are_redacted_erased_and_not_serializable_or_cloneable(): void
    {
        $archive = new EncryptedArchive(self::PASSWORD);
        $secrets = [];
        foreach (['key', 'initialState'] as $name) {
            $secrets[] = (new \ReflectionProperty($archive, $name))->getValue($archive);
        }
        try {
            ob_start();
            var_dump($archive);
            $debug = ob_get_clean();
            foreach (array_merge([self::PASSWORD], $secrets) as $secret) {
                $this->assertStringNotContainsString($secret, $debug);
                $this->assertStringNotContainsString(bin2hex($secret), $debug);
                $this->assertStringNotContainsString(base64_encode($secret), $debug);
            }
            $this->assertSame('{}', json_encode($archive));
            try {
                serialize($archive);
                $this->fail('Serialization must be prohibited.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot be serialized', $e->getMessage());
            }
            try {
                $archive->__unserialize([]);
                $this->fail('Unserialization must be prohibited.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot be unserialized', $e->getMessage());
            }
            try {
                clone $archive;
                $this->fail('Cloning must be prohibited.');
            } catch (\Error $e) {
                $this->assertStringContainsString('__clone', $e->getMessage());
            }
        } finally {
            $archive->close();
            $archive->close();
        }

        foreach (['key', 'initialState', 'activeState'] as $name) {
            $this->assertNull((new \ReflectionProperty($archive, $name))->getValue($archive));
        }
        $this->assertTrue($archive->__debugInfo()['closed']);
        $this->expectException(LogicException::class);
        iterator_to_array($archive->encrypt(['no reuse after close']));
    }

    public function test_concurrent_replay_is_rejected_without_disturbing_the_active_generation(): void
    {
        $archive = new EncryptedArchive(self::PASSWORD);
        try {
            $first = $archive->encrypt(['payload']);
            $this->assertSame(64, strlen($first->current()));
            try {
                iterator_to_array($archive->encrypt(['payload']));
                $this->fail('Concurrent generators must be rejected.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('concurrently', $e->getMessage());
            }
            $this->assertSame('payload', $this->decrypt(implode('', iterator_to_array($first))));
        } finally {
            $archive->close();
        }
    }

    public function test_closing_a_suspended_generator_erases_active_state_and_prevents_more_ciphertext(): void
    {
        $archive = new EncryptedArchive(self::PASSWORD);
        $generator = $archive->encrypt([str_repeat('x', 100000)]);
        $generator->current();
        $generator->next();
        $archive->close();
        $this->assertNull((new \ReflectionProperty($archive, 'activeState'))->getValue($archive));
        $this->expectException(LogicException::class);
        $generator->next();
    }

    public function test_non_string_input_is_rejected_and_generation_state_is_released(): void
    {
        $archive = new EncryptedArchive(self::PASSWORD);
        try {
            try {
                iterator_to_array($archive->encrypt([123]));
                $this->fail('Non-string input must fail.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('only strings', $e->getMessage());
            }
            $this->assertNull((new \ReflectionProperty($archive, 'activeState'))->getValue($archive));
        } finally {
            $archive->close();
        }
    }

    public function test_streaming_64_mib_uses_bounded_php_heap_for_encryption_and_decryption(): void
    {
        $encrypted = tmpfile();
        $decrypted = tmpfile();
        $archive = new EncryptedArchive(self::PASSWORD);
        $totalBytes = 64 * 1024 * 1024 + 123;
        $expectedHash = hash_init('sha256');
        $source = (static function () use ($totalBytes, $expectedHash) {
            $chunk = str_repeat('0123456789abcdef', 4096);
            for ($remaining = $totalBytes; $remaining > 0; $remaining -= strlen($part)) {
                $part = substr($chunk, 0, min(strlen($chunk), $remaining));
                hash_update($expectedHash, $part);
                yield $part;
            }
        })();

        // PHP heap measurements exclude Argon2id's pinned 64 MiB native work
        // allocation. This test measures payload-dependent buffering only.
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);
        try {
            foreach ($archive->encrypt($source) as $bytes) {
                $this->assertLessThanOrEqual(65557, strlen($bytes));
                $this->assertSame(strlen($bytes), fwrite($encrypted, $bytes));
            }
            $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
            $expected = hash_final($expectedHash);
            rewind($encrypted);
            memory_reset_peak_usage();
            $baseline = memory_get_usage(true);
            EncryptedArchive::decrypt($encrypted, $decrypted, self::PASSWORD);
            $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
            $this->assertSame($totalBytes, ftell($decrypted));
            rewind($decrypted);
            $actualHash = hash_init('sha256');
            hash_update_stream($actualHash, $decrypted);
            $this->assertSame($expected, hash_final($actualHash));
        } finally {
            $archive->close();
            fclose($encrypted);
            fclose($decrypted);
        }
    }

    public function test_closed_and_identical_stream_resources_are_rejected(): void
    {
        $stream = fopen('php://memory', 'w+b');
        try {
            try {
                EncryptedArchive::decrypt($stream, $stream, self::PASSWORD);
                $this->fail('Input cannot also be staging.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('different', $e->getMessage());
            }
        } finally {
            fclose($stream);
        }
        $this->expectException(InvalidArgumentException::class);
        EncryptedArchive::decrypt($stream, $stream, self::PASSWORD);
    }

    public function test_short_reads_and_writes_are_completed_and_physical_eof_is_required(): void
    {
        $plaintext = str_repeat('short stream IO ', 9000);
        EncryptedArchivePartialStream::$input = $this->encrypt([$plaintext]);
        EncryptedArchivePartialStream::$failWrites = false;
        EncryptedArchivePartialStream::$withholdEof = false;
        $this->assertTrue(stream_wrapper_register('capsulearchivetest', EncryptedArchivePartialStream::class));
        try {
            $input = fopen('capsulearchivetest://input', 'rb');
            $output = fopen('capsulearchivetest://output', 'wb');
            try {
                EncryptedArchive::decrypt($input, $output, self::PASSWORD);
                $this->assertSame($plaintext, EncryptedArchivePartialStream::$output);
            } finally {
                fclose($input);
                fclose($output);
            }

            EncryptedArchivePartialStream::$withholdEof = true;
            $input = fopen('capsulearchivetest://input', 'rb');
            $output = fopen('capsulearchivetest://output', 'wb');
            try {
                $this->expectException(RuntimeException::class);
                $this->expectExceptionMessage('end immediately after its final frame');
                EncryptedArchive::decrypt($input, $output, self::PASSWORD);
            } finally {
                fclose($input);
                fclose($output);
            }
        } finally {
            stream_wrapper_unregister('capsulearchivetest');
            EncryptedArchivePartialStream::$withholdEof = false;
            EncryptedArchivePartialStream::$input = '';
            EncryptedArchivePartialStream::$output = '';
        }
    }

    public function test_failed_staging_write_throws_without_claiming_archive_completion(): void
    {
        EncryptedArchivePartialStream::$input = $this->encrypt(['private payload']);
        EncryptedArchivePartialStream::$failWrites = true;
        $this->assertTrue(stream_wrapper_register('capsulearchivetest', EncryptedArchivePartialStream::class));
        $input = fopen('capsulearchivetest://input', 'rb');
        $output = fopen('capsulearchivetest://output', 'wb');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('private staging');
            EncryptedArchive::decrypt($input, $output, self::PASSWORD);
        } finally {
            $this->assertSame('', EncryptedArchivePartialStream::$output);
            fclose($input);
            fclose($output);
            stream_wrapper_unregister('capsulearchivetest');
            EncryptedArchivePartialStream::$failWrites = false;
            EncryptedArchivePartialStream::$input = '';
            EncryptedArchivePartialStream::$output = '';
        }
    }

    private function encrypt(iterable $plaintext): string
    {
        $archive = new EncryptedArchive(self::PASSWORD);
        try {
            return implode('', iterator_to_array($archive->encrypt($plaintext)));
        } finally {
            $archive->close();
        }
    }

    private function decrypt(string $ciphertext, #[\SensitiveParameter] string $password = self::PASSWORD): string
    {
        $input = $this->stream($ciphertext);
        $output = fopen('php://memory', 'w+b');
        try {
            EncryptedArchive::decrypt($input, $output, $password);
            rewind($output);

            return stream_get_contents($output);
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function stream(string $contents)
    {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    private function frames(string $ciphertext): array
    {
        $frames = [];
        for ($offset = self::HEADER_BYTES; $offset < strlen($ciphertext); $offset += 4 + $length) {
            $length = unpack('Nlength', substr($ciphertext, $offset, 4))['length'];
            $frames[] = substr($ciphertext, $offset, 4 + $length);
        }

        return $frames;
    }

    private function profile(): string
    {
        return "CAPSENC\0\x01\x01\x01\x00" . pack('NNN', 2, 67108864, self::FRAME_BYTES);
    }

    private function nativeArchive(array $messages, ?int $wrongAdFrame = null): string
    {
        $salt = random_bytes(16);
        $key = sodium_crypto_pwhash(32, self::PASSWORD, $salt, 2, 67108864, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        [$state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $header = $this->profile() . $salt . $streamHeader;
        $result = $header;
        try {
            foreach ($messages as $i => [$plaintext, $tag]) {
                $frame = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plaintext, $i === $wrongAdFrame ? substr($header, 0, 40) : $header, $tag);
                $result .= pack('N', strlen($frame)) . $frame;
            }

            return $result;
        } finally {
            sodium_memzero($key);
            sodium_memzero($state);
        }
    }
}

/** Test-only blocking stream with deliberately partial reads and writes. */
class EncryptedArchivePartialStream
{
    public $context;
    public static string $input = '';
    public static string $output = '';
    public static bool $failWrites = false;
    public static bool $withholdEof = false;
    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if ($mode === 'wb') {
            self::$output = '';
        }

        return true;
    }

    public function stream_read(int $count): string
    {
        $bytes = substr(self::$input, $this->position, min(7, $count));
        $this->position += strlen($bytes);

        return $bytes;
    }

    public function stream_write(string $bytes): int
    {
        if (self::$failWrites) {
            return 0;
        }
        $bytes = substr($bytes, 0, 11);
        self::$output .= $bytes;
        $this->position += strlen($bytes);

        return strlen($bytes);
    }

    public function stream_eof(): bool
    {
        return !self::$withholdEof && $this->position >= strlen(self::$input);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_stat(): array
    {
        return [];
    }
}
