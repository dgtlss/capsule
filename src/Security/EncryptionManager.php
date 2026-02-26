<?php

namespace Dgtlss\Capsule\Security;

use Exception;

class EncryptionManager
{
    protected string $method;

    public function __construct()
    {
        $this->method = config('capsule.security.encryption_method', 'AES-256-CBC');
    }

    /**
     * Encrypt a file using envelope encryption:
     * 1. Generate a random data encryption key (DEK)
     * 2. Encrypt the file with the DEK
     * 3. Encrypt the DEK with the master key (KEK)
     * 4. Prepend the encrypted DEK to the output file
     *
     * Returns the encrypted file path and metadata for manifest.
     */
    public function encryptFile(string $inputPath, string $outputPath): array
    {
        $masterKey = $this->getMasterKey();
        if (empty($masterKey)) {
            throw new Exception('Backup encryption key not configured. Set CAPSULE_BACKUP_PASSWORD.');
        }

        $dek = random_bytes(32);
        $iv = random_bytes(openssl_cipher_iv_length($this->method));

        $inputHandle = fopen($inputPath, 'rb');
        $outputHandle = fopen($outputPath, 'wb');
        if (!$inputHandle || !$outputHandle) {
            throw new Exception("Cannot open files for encryption");
        }

        $encryptedDek = $this->wrapKey($dek, $masterKey);
        $header = json_encode([
            'version' => 2,
            'method' => $this->method,
            'iv' => base64_encode($iv),
            'wrapped_key' => base64_encode($encryptedDek),
            'key_id' => $this->getKeyId(),
        ]);

        $headerLength = pack('N', strlen($header));
        fwrite($outputHandle, $headerLength);
        fwrite($outputHandle, $header);

        while (!feof($inputHandle)) {
            $chunk = fread($inputHandle, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $encrypted = openssl_encrypt($chunk, $this->method, $dek, OPENSSL_RAW_DATA, $iv);
            $chunkLength = pack('N', strlen($encrypted));
            fwrite($outputHandle, $chunkLength);
            fwrite($outputHandle, $encrypted);
        }

        fclose($inputHandle);
        fclose($outputHandle);

        return [
            'method' => $this->method,
            'key_id' => $this->getKeyId(),
            'envelope_version' => 2,
        ];
    }

    /**
     * Decrypt a file that was encrypted with envelope encryption.
     */
    public function decryptFile(string $inputPath, string $outputPath): void
    {
        $masterKey = $this->getMasterKey();
        if (empty($masterKey)) {
            throw new Exception('Decryption key not configured. Set CAPSULE_BACKUP_PASSWORD.');
        }

        $inputHandle = fopen($inputPath, 'rb');
        $outputHandle = fopen($outputPath, 'wb');
        if (!$inputHandle || !$outputHandle) {
            throw new Exception("Cannot open files for decryption");
        }

        $headerLengthRaw = fread($inputHandle, 4);
        $headerLength = unpack('N', $headerLengthRaw)[1];
        $headerJson = fread($inputHandle, $headerLength);
        $header = json_decode($headerJson, true);

        if (!$header || ($header['version'] ?? 0) < 2) {
            fclose($inputHandle);
            fclose($outputHandle);
            throw new Exception('Unsupported encryption format version.');
        }

        $method = $header['method'] ?? $this->method;
        $iv = base64_decode($header['iv']);
        $encryptedDek = base64_decode($header['wrapped_key']);

        $dek = $this->unwrapKey($encryptedDek, $masterKey);

        while (!feof($inputHandle)) {
            $chunkLengthRaw = fread($inputHandle, 4);
            if ($chunkLengthRaw === false || strlen($chunkLengthRaw) < 4) {
                break;
            }
            $chunkLength = unpack('N', $chunkLengthRaw)[1];
            $encrypted = fread($inputHandle, $chunkLength);

            $decrypted = openssl_decrypt($encrypted, $method, $dek, OPENSSL_RAW_DATA, $iv);
            if ($decrypted === false) {
                fclose($inputHandle);
                fclose($outputHandle);
                throw new Exception('Decryption failed. Wrong key or corrupt data.');
            }

            fwrite($outputHandle, $decrypted);
        }

        fclose($inputHandle);
        fclose($outputHandle);
    }

    /**
     * Wrap (encrypt) the DEK with the master key.
     */
    protected function wrapKey(string $dek, string $masterKey): string
    {
        $derivedKey = hash('sha256', $masterKey, true);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($dek, 'AES-256-CBC', $derivedKey, OPENSSL_RAW_DATA, $iv);

        return $iv . $encrypted;
    }

    /**
     * Unwrap (decrypt) the DEK with the master key.
     */
    protected function unwrapKey(string $wrapped, string $masterKey): string
    {
        $derivedKey = hash('sha256', $masterKey, true);
        $iv = substr($wrapped, 0, 16);
        $encrypted = substr($wrapped, 16);

        $dek = openssl_decrypt($encrypted, 'AES-256-CBC', $derivedKey, OPENSSL_RAW_DATA, $iv);
        if ($dek === false) {
            throw new Exception('Failed to unwrap data encryption key. Master key may be wrong.');
        }

        return $dek;
    }

    protected function getMasterKey(): string
    {
        return (string) (config('capsule.security.backup_password') ?? env('CAPSULE_BACKUP_PASSWORD', ''));
    }

    /**
     * Generate a key identifier for tracking which master key version was used.
     */
    protected function getKeyId(): string
    {
        $key = $this->getMasterKey();
        return substr(hash('sha256', $key), 0, 8);
    }
}
