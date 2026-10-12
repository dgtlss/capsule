# Encrypted streaming backups: Capsule required for recovery

> **`php artisan capsule:backup --no-local --encrypt` produces a `.capsule.enc` file, not a standard password-protected ZIP. You need a compatible Capsule package and the backup password to decrypt it. Ordinary ZIP tools cannot open the encrypted file.** Keep a tested Capsule installation, its dependency lock, and this format documentation in your recovery kit; store the password separately. Test recovery without the original application before relying on these backups.

The stream contains a compressed ZIP encrypted by PHP's native sodium extension. Capsule does not spool a complete archive to local disk on this backup path. Capture chunks are encrypted before remote storage writes as well. Missing cryptographic capabilities or a password fail closed; Capsule never silently switches an encrypted backup to plaintext.

Standard `php artisan capsule:backup --encrypt` is unchanged: it uses local staging and native `ZipArchive`/libzip to create an AES-encrypted ZIP. Standard and legacy AES ZIPs still use the existing restore path and an AES-capable ZIP reader for manual extraction. They have a different password KDF and expose ZIP member names and sizes. The stronger streaming format does not retroactively change those archives. [Historical backup compatibility](recovery-audit.md#historical-backup-compatibility) covers real old regular and `--no-local` ZIPs, including AES, legacy password behavior, unchecksummed streamed database members, old incrementals, and Unix filenames. These cases retain explicit warnings where the old writer omitted integrity or deletion information.

## Standalone decryption and disaster recovery

The installed Composer package supplies `vendor/bin/capsule-decrypt`. It loads the package's dependencies but does **not** boot Laravel. You do not need the original Laravel application, its database, the backup catalog, or the original storage account after downloading the final file. You do need PHP, the required extensions, a compatible Capsule package and installed dependencies, the encrypted file, and its password.

```bash
# Hidden password prompt
vendor/bin/capsule-decrypt /safe/backup.capsule.enc --output=/private/recovered.zip

# Noninteractive use with a protected, single-line password file
vendor/bin/capsule-decrypt /safe/backup.capsule.enc --output=/private/recovered.zip --password-stdin < /secure/backup-password

# Equivalent command in an available Laravel application
php artisan capsule:decrypt /safe/backup.capsule.enc --output=/private/recovered.zip
```

Both decrypt commands support `--format=json`. The password is accepted only through a hidden terminal prompt or explicit `--password-stdin`, never through a literal command-line argument, environment fallback, or application configuration. The stdin option reads one password line and removes its line ending. Ordinary Laravel backup/restore commands continue to use `capsule.security.backup_password` / `CAPSULE_BACKUP_PASSWORD` from the application's secret configuration. Streaming passwords must contain 1–4096 bytes and no NUL, CR, or LF bytes. Capsule does not trim, truncate, or Unicode-normalize password contents; the stdin transport removes only its final line ending. Use a generated high-entropy password and keep it separately from the backup. Standard/legacy ZIP passwords retain their existing behavior, with NUL bytes rejected.

The output is a **normal, unencrypted ZIP**. The output directory must already exist and have room for the complete plaintext ZIP. The command writes private owner-only staging, authenticates the entire stream through its final frame and physical EOF, then validates the ZIP manifest, paths, and payload checksums before publishing a new owner-only output file. Existing destinations are never overwritten; source/output symlink paths are rejected. Handled failures remove partial plaintext staging. A power loss or forced process kill can leave private temporary files; inspect and clean up those files before reusing a recovery machine. Keep the recovered ZIP private and remove it when finished.

`capsule:restore --archive=/safe/backup.capsule.enc` recognizes the encrypted container and performs decryption before the existing validation/restore flow. All archive checks finish before destination files or databases are changed. Restore also needs local space for the decrypted ZIP and payload staging. An incremental still needs its checksum-matching full base; decrypting the incremental alone does not recover unchanged files. Keep the original encrypted base: the manifest checksum binds that stored object, and a decrypted ZIP is not a checksum-equivalent `--base-archive` replacement.

`capsule:verify` and `capsule:inspect` also recognize and decrypt the format through private staging. For remotely stored archives, allow space for both the complete encrypted download and the complete decrypted ZIP; restore additionally needs expanded payload staging. This requirement applies even if you only intend to verify or list content.

Preserve more than a download URL: retain the compatible Capsule package/source version, its format documentation, a working installation with dependencies, and the Composer dependency lock. The package contains the recovery library; it does not require the original application to decrypt. A lockfile alone cannot supply packages during an offline disaster. Perform an isolated drill with the exact recovery kit and password, then validate file/database recovery in a safe destination. Repeat the drill when changing versions, formats, passwords, or recovery hosts. No tool can recover a lost password.

## Final archive: confidentiality and authentication

The final format identifier is `capsule-secretstream-v1`, with the `.capsule.enc` extension. It uses libsodium's XChaCha20-Poly1305 secretstream API, not a hand-written cipher or a ZIP encryption adapter. A compressed ZIP is the encrypted payload, so file contents, member names, directory structure, and `manifest.json` are all hidden. The final object name, total size, public header, frame lengths, and upload timing remain observable.

A 32-byte key is derived from the password with native Argon2id13, a fresh random 16-byte salt, two passes, and 64 MiB of working memory. Each new archive has a fresh random 24-byte secretstream header. The fixed version 1 KDF and frame profile is checked before key derivation, so an untrusted header cannot choose arbitrary memory/CPU costs. See [libsodium password hashing](https://doc.libsodium.org/doc/password_hashing/default_phf), [libsodium secretstream](https://doc.libsodium.org/secret-key_cryptography/secretstream), and [PHP secretstream encryption](https://www.php.net/manual/en/function.sodium-crypto-secretstream-xchacha20poly1305-push.php).

Every frame authenticates the entire public header as additional data. Ordered message frames, a separate authenticated empty final frame, and an immediate physical EOF are required. Wrong passwords, altered data, invalid profiles, missing or reordered frames, truncation, and trailing bytes cause failure. Per-frame authentication does not by itself establish that the whole file is complete: the plaintext must stay in private staging until the final frame, EOF, and ZIP validation succeed.

The writer can replay the exact same immutable, verified archive stream for fingerprinting, retries, and replication. Its encryption instance must never be reused for different content; a new archive requires fresh salt and state. This is an internal replay contract, not a general-purpose mutable-stream encryption API.

## Temporary remote objects and catalog exposure

Capture chunks are encrypted **before** any storage call. They are not ZIPs or final `.capsule.enc` files: `EncryptedChunkCodec` uses its own versioned envelope containing an 8-byte format header, random 24-byte nonce, and native sodium XChaCha20-Poly1305 ciphertext with a 16-byte authentication tag. The header and exact remote object path are authenticated additional data. Renaming encrypted chunks breaks that authentication.

Each capture run derives a separate chunk key with Argon2id13, its own random 16-byte salt, two passes, and 64 MiB of KDF working memory. The chunk-key and final-archive-key derivations are separate sequential calls, each requiring 64 MiB of native working memory. Every chunk gets a new random nonce. The metadata's fixed KDF profile is validated before derivation. Stored ciphertext size and SHA-256 are verified after upload. Before collation, each complete bounded chunk is authenticated and its plaintext size/hash checked, then yielded to compression and final archive encryption. Successful runs remove temporary chunks; failed final publication retains encrypted chunk references and their public KDF metadata. The service clears its key/password references in `finally`.

The backup catalog is **not encrypted by this feature**. It can expose storage paths, file/connection names, sizes, checksums, policy metadata, error messages, chunk references, and public KDF profiles/salts. It contains neither the encryption key nor the backup password. Protect the application database, logs, and storage bucket. Chunk names are opaque, but counts, lengths, and timing remain observable. Source files and any SQLite snapshot remain plaintext on the application's host under the existing private-staging controls.

## Resource budgets and failed-upload recovery

Payload memory is bounded by a small multiple of the configured capture chunk size plus compressor/adapter buffers, rather than total backup size. Capture chunk size is 1 byte–64 MiB (default 10 MiB); final encryption uses fixed 64 KiB plaintext frames. Allow 64 MiB of additional native memory during each Argon2id key derivation, including recovery. PHP's reported memory peak may not include that allocation. File/chunk metadata and the ZIP central directory grow with entry count. New-backup manifests remain capped at 16 MiB. Readers default to that budget; for trusted older archives, restore can explicitly override `capsule.restore.max_manifest_bytes` with a positive decimal `--max-manifest-bytes=N` (for example, `33554432` for 32 MiB), up to `PHP_INT_MAX`. Inspect/verify use the configuration value. There is no unlimited mode. The budget applies per archive, including incremental bases; decoded JSON arrays may use several times the encoded size, so raise it only on a suitably provisioned recovery host. Path, schema, and recorded checksum validation remain mandatory. Encryption and read-back verification add CPU and storage reads; benchmark the actual host and adapter.

`--no-local` is not an absolute zero-local-disk guarantee. SQLite still needs a consistent temporary on-disk database snapshot, potentially as large as the database. This does not create a full local archive spool. Filesystem adapters can buffer internally; the S3 adapter is configured for streamed reads. Decryption and restore deliberately require local plaintext staging regardless of how the backup was created.

Normal recovery uses the final `.capsule.enc` file, compatible Capsule, and the password; temporary-chunk metadata is not needed. For a failed run, retain the catalog's entire `recovery_chunks` list and `chunk_encryption` metadata together with every referenced object and the original password. A maintenance script can reconstruct `EncryptedChunkCodec::fromMetadata($password, $metadata)`, set it on `StorageManager`, and call `collateChunksAdvanced()` with the recorded complete list and encryption enabled. Always close the codec and clear it from the manager in `finally`. This is an internal recovery API, not a new CLI command. It cannot recover an incomplete capture or a lost password. Verify the reconstructed archive before changing destination data.

## Runtime and migration checklist

PHP's native `sodium` extension with Argon2id13 and secretstream support is required. ZIP validation uses `ext-zip`; compression uses `zlib`. Standard encrypted ZIP creation and legacy AES ZIP recovery require an AES-capable libzip build. The streaming encryption implementation uses PHP and native sodium; it has **no Python runtime and no `nelexa/zip` dependency**.

Before enabling or migrating to encrypted streaming:

1. Make the `.capsule.enc` and **Capsule-required decryption** requirement explicit to operators and recovery owners. Do not label the output as a standard encrypted ZIP or distribute ZIP-only extraction instructions.
2. Provision a compatible PHP/sodium/ZIP recovery host, retain the Capsule recovery kit and dependency lock, and verify password access separately from backup storage.
3. Budget SQLite snapshot space during backup and plaintext staging space during decryption/restore. Measure native KDF memory as well as PHP and adapter memory.
4. Run an isolated decryption and restore drill, including any incremental base. Keep existing standard/legacy AES ZIP recovery procedures and passwords for retained older backups.
5. Protect the plaintext catalog and temporary recovery output, and document how to preserve failed-upload chunks and metadata.
6. Test retained old backups as well as new ones. Historical password `"0"` produced plaintext; old NUL-containing passwords used the pre-NUL prefix in native libzip. Old streamed database members may have only native ZIP CRC/AES integrity evidence, and old incrementals lack base hashes and deletions. Preserve and review the compatibility warnings; do not present those missing historical guarantees as newly verified.

The CI matrix declares Laravel 12 on PHP 8.2/8.3/8.4/8.5 and Laravel 13 on PHP 8.3/8.4/8.5. A declared matrix or regression test is not evidence that the final commit passed it. Review final test/CI results and run live storage/database recovery checks before rollout. The independent security review is **incomplete**: a format-dispatch issue found during the review was fixed with focused regression coverage, but the review stopped before completion. Complete a manual security review before merge or release. The format, regression tests, and partial review are not an independent professional cryptographic audit. There is no master-key rotation or externally managed key service.

## Version 1 wire format

This appendix records the format implemented in [`EncryptedArchive`](../src/Security/EncryptedArchive.php), so the recovery contract can be retained with the package. Existing version 1 readers accept only the fixed profile below; future incompatible formats need an explicitly supported version. The extension alone does not establish validity.

The first 64 bytes are public. All unsigned integers below use network byte order (big-endian):

| Byte offsets | Length | Value |
| --- | --- | --- |
| 0–7 | 8 | ASCII `CAPSENC` followed by one NUL byte (`43 41 50 53 45 4e 43 00`) |
| 8 | 1 | Format version: `1` |
| 9 | 1 | KDF identifier: `1` = Argon2id13 |
| 10 | 1 | Cipher identifier: `1` = XChaCha20-Poly1305 secretstream |
| 11 | 1 | Reserved: `0` |
| 12–15 | 4 | Argon2id opslimit: `2` |
| 16–19 | 4 | Argon2id memlimit in bytes: `67108864` |
| 20–23 | 4 | Plaintext frame size: `65536` |
| 24–39 | 16 | Random Argon2id salt |
| 40–63 | 24 | Native sodium secretstream header |

Derive the 32-byte secretstream key with `sodium_crypto_pwhash`, the recorded salt, opslimit `2`, memlimit `67108864`, and `SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13`. Initialize native secretstream with that key and the 24-byte stream header. The entire 64-byte container header is the additional authenticated data for **every** frame.

The header is followed by a sequence of four-byte unsigned ciphertext lengths and the corresponding native secretstream ciphertext bytes. Each ciphertext carries 17 bytes of secretstream overhead. A reader bounds ciphertext length to `17..65553` before allocation and authentication, then checks these canonical rules:

- Data frames use `TAG_MESSAGE` (`0`) and contain exactly 65,536 plaintext bytes, except that the final data frame may contain 1–65,535 bytes. Empty data frames are invalid, and no data frame may follow a short data frame.
- A separate empty `TAG_FINAL` (`3`) frame is mandatory, giving a 17-byte ciphertext and four-byte length prefix. An empty overall plaintext consists only of this final frame after the header.
- No other tags are accepted. Physical EOF must follow the final frame immediately; trailing bytes are invalid.

The inner ZIP must still pass Capsule's independent manifest, path, and checksum validation before it is published or restored. The format has no password-recovery mechanism and no plaintext fallback.
