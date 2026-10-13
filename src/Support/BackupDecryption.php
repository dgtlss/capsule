<?php

namespace Dgtlss\Capsule\Support;

use Dgtlss\Capsule\Security\EncryptedArchive;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Question\Question;

/** Recovery requires Composer dependencies and PHP, but no Laravel application. */
final class BackupDecryption
{
    public const HELP = 'Capsule required to decrypt .capsule.enc; result is a normal ZIP. '
        . 'Decryption stages a private plaintext ZIP to disk and publishes only after authentication and checksum verification. '
        . 'Keep the password and the Capsule package available for disaster recovery. '
        . 'Use the hidden password prompt, or --password-stdin with redirected input. '
        . 'Passwords are never read from arguments, environment variables, or application configuration.';

    /** Authenticate and verify before atomically publishing a new, owner-only ZIP. */
    public static function decryptToFile(string $source, string $destination, #[SensitiveParameter] string $password): array
    {
        $temporary = null;
        $output = null;
        try {
            $destination = self::destinationPath($destination);
            self::createPrivateFile(dirname($destination), $temporary, $output);
            $entries = self::decryptAndVerify($source, $temporary, $output, $password);
            fclose($output);
            $output = null;

            // Unlike rename(), link() cannot replace an existing destination,
            // including a symlink created after our initial safety checks.
            if (!@link($temporary, $destination)) {
                throw new RuntimeException('Cannot publish decrypted ZIP: the destination exists or atomic no-overwrite publication is unavailable.');
            }

            return self::formatMetadata() + [
                'status' => 'success',
                'output' => $destination,
                'entries_checked' => $entries,
            ];
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            if ($temporary !== null) {
                @unlink($temporary);
            }
            self::erasePassword($password);
        }
    }

    /**
     * Return a fully verified 0600 ZIP. The caller owns it and MUST unlink it in
     * a finally block. Failure removes all partial plaintext before returning.
     */
    public static function decryptToTemporaryFile(string $source, #[SensitiveParameter] string $password): string
    {
        $temporary = null;
        $output = null;
        $complete = false;
        try {
            $directory = realpath(sys_get_temp_dir());
            if ($directory === false) {
                throw new RuntimeException('The temporary directory is unavailable.');
            }
            self::createPrivateFile($directory, $temporary, $output);
            self::decryptAndVerify($source, $temporary, $output, $password);
            $complete = true;

            return $temporary;
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            if (!$complete && $temporary !== null) {
                @unlink($temporary);
            }
            self::erasePassword($password);
        }
    }

    /** Shared console adapter; neither command bootstraps an application here. */
    public static function runConsole(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('format') === 'json';
        $password = null;
        $signalHandlers = [];
        $asyncSignals = null;
        $cancelled = false;
        try {
            if (!in_array($input->getOption('format'), ['text', 'json'], true)) {
                throw new RuntimeException('Output format must be text or json.');
            }
            $destination = $input->getOption('output');
            if (!is_string($destination) || $destination === '') {
                throw new RuntimeException('Specify --output with a new local ZIP file path.');
            }

            // Make ordinary cancellation unwind the private-file finally blocks.
            // SIGKILL/power failure cannot be handled by any PHP cleanup routine.
            if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal_get_handler')) {
                $asyncSignals = pcntl_async_signals(true);
                foreach ([SIGINT, SIGTERM] as $signal) {
                    $signalHandlers[$signal] = pcntl_signal_get_handler($signal);
                    pcntl_signal($signal, static function () use (&$cancelled): never {
                        $cancelled = true;
                        throw new RuntimeException('Decryption cancelled.');
                    });
                }
            }

            $password = self::readPassword($input, $output);
            $result = self::decryptToFile((string) $input->getArgument('source'), $destination, $password);
            if ($json) {
                self::writeJson($output, $result);
            } else {
                $output->writeln('Verified and decrypted ZIP: ' . $result['output'], OutputInterface::OUTPUT_RAW);
                $output->writeln('The output is a normal ZIP. It contains plaintext; keep it private.');
            }

            return 0;
        } catch (\Throwable $e) {
            $error = $cancelled ? 'Decryption cancelled.' : $e->getMessage();
            if ($json) {
                self::writeJson($output, self::formatMetadata() + ['status' => 'failed', 'error' => $error]);
            } else {
                $output->writeln('Decryption failed: ' . $error, OutputInterface::OUTPUT_RAW);
            }

            return 1;
        } finally {
            foreach ($signalHandlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            if ($asyncSignals !== null) {
                pcntl_async_signals($asyncSignals);
            }
            self::erasePassword($password);
        }
    }

    /** Reject accidental literal secrets before the console parser can echo them. */
    public static function rejectPasswordArguments(InputInterface $input, OutputInterface $output): bool
    {
        if (!$input->hasParameterOption('--password', true)) {
            return false;
        }
        $error = 'Passwords cannot be supplied as arguments. Use the hidden prompt or --password-stdin.';
        if ($input->getParameterOption('--format', 'text', true) === 'json') {
            self::writeJson($output, self::formatMetadata() + ['status' => 'failed', 'error' => $error]);
        } else {
            $output->writeln($error);
        }

        return true;
    }

    /** Parser exceptions can contain arbitrary argv values, including secrets. */
    public static function reportInvalidArguments(InputInterface $input, OutputInterface $output): int
    {
        $error = 'Invalid decryption arguments. Run --help for usage. Supply one source and --output; use the hidden password prompt or --password-stdin, never a password argument.';
        if ($input->getParameterOption('--format', 'text', true) === 'json') {
            self::writeJson($output, self::formatMetadata() + ['status' => 'failed', 'error' => $error]);
        } else {
            $output->writeln($error);
        }

        return 1;
    }

    private static function formatMetadata(): array
    {
        return [
            'format' => EncryptedArchive::FORMAT,
            'input_format' => EncryptedArchive::FORMAT,
            'requires_capsule' => true,
            'output_format' => 'zip',
            'output_requires_capsule' => false,
        ];
    }

    private static function writeJson(OutputInterface $output, array $result): void
    {
        $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
    }

    private static function readPassword(InputInterface $input, OutputInterface $output): string
    {
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $stream ??= STDIN;
        if ($input->getOption('password-stdin')) {
            if (function_exists('stream_isatty') && stream_isatty($stream)) {
                throw new RuntimeException('--password-stdin requires redirected input. Omit it for a hidden terminal prompt.');
            }
            $line = fgets($stream, 4098);
            if ($line === false) {
                throw new RuntimeException('No password was supplied on standard input.');
            }
            $password = $line;
            self::erasePassword($line);
        } else {
            if (!$input->isInteractive() || !function_exists('stream_isatty') || !stream_isatty($stream)) {
                throw new RuntimeException('A password is required. Use a hidden terminal prompt or explicitly supply --password-stdin with redirected input.');
            }
            $question = new Question('Backup password (hidden): ');
            $question->setHidden(true)->setHiddenFallback(false)->setTrimmable(false);
            $stderr = fopen('php://stderr', 'wb');
            if ($stderr === false) {
                throw new RuntimeException('Cannot open a secure password prompt. Use --password-stdin with redirected input.');
            }
            try {
                $password = (new QuestionHelper())->ask($input, new StreamOutput($stderr, $output->getVerbosity(), $output->isDecorated()), $question);
            } finally {
                fclose($stderr);
            }
        }
        // Symfony's untrimmed hidden response can include the line ending.
        // Remove only that terminator, preserving meaningful password spaces.
        if (is_string($password) && str_ends_with($password, "\n")) {
            $password = substr($password, 0, -1);
            if (str_ends_with($password, "\r")) {
                $password = substr($password, 0, -1);
            }
        }
        if (!is_string($password) || $password === '' || strlen($password) > 4096 || strpbrk($password, "\0\r\n") !== false) {
            self::erasePassword($password);
            throw new RuntimeException('Supply a non-empty password of at most 4096 bytes without NUL, CR, or LF characters.');
        }

        return $password;
    }

    /** @param resource $output */
    private static function decryptAndVerify(string $source, string $temporary, $output, #[SensitiveParameter] string $password): int
    {
        $input = null;
        $archive = null;
        try {
            self::assertLocalPath($source);
            self::assertNoSymlinks($source);
            if (!is_file($source) || !is_readable($source)) {
                throw new RuntimeException('The encrypted backup must be a readable local file.');
            }
            if (!EncryptedArchive::isEncryptedFile($source)) {
                throw new RuntimeException('This is not a Capsule .capsule.enc archive. Ordinary ZIPs do not need this command; use capsule:restore for legacy password-protected ZIPs.');
            }
            $input = @fopen($source, 'rb');
            if ($input === false) {
                throw new RuntimeException('Cannot read the encrypted backup.');
            }
            $opened = fstat($input);
            $current = @lstat($source);
            if ($current === false || $opened === false || ($current['mode'] & 0170000) !== 0100000
                || $current['ino'] !== $opened['ino'] || $current['dev'] !== $opened['dev']) {
                throw new RuntimeException('The encrypted backup changed while it was being opened.');
            }
            EncryptedArchive::decrypt($input, $output, $password);
            if (!fflush($output)) {
                throw new RuntimeException('Cannot flush the decrypted ZIP.');
            }
            $archive = new RestoreArchive($temporary);
            $archive->verifyAndStage(false);

            return count($archive->entries());
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            $archive?->close();
            self::erasePassword($password);
        }
    }

    private static function destinationPath(string $path): string
    {
        self::assertLocalPath($path);
        self::assertNoSymlinks($path);
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('The destination already exists; choose a new output file.');
        }
        if (str_ends_with($path, DIRECTORY_SEPARATOR) || in_array(basename($path), ['.', '..'], true)) {
            throw new RuntimeException('The destination must name a new file.');
        }
        $directory = realpath(dirname($path));
        if ($directory === false || !is_dir($directory)) {
            throw new RuntimeException('The output directory must already exist.');
        }

        return $directory . DIRECTORY_SEPARATOR . basename($path);
    }

    /** @param resource|null $output The caller's finally block owns this handle. */
    private static function createPrivateFile(string $directory, ?string &$path, &$output): void
    {
        $candidate = $directory . DIRECTORY_SEPARATOR . '.capsule_decrypt_' . bin2hex(random_bytes(16)) . '.tmp';
        $signalMask = null;
        // Do not deliver cancellation between creating a file and giving its
        // ownership to the caller's cleanup block.
        if (function_exists('pcntl_sigprocmask')) {
            pcntl_sigprocmask(SIG_BLOCK, [SIGINT, SIGTERM], $signalMask);
        }
        try {
            $mask = umask(0077);
            try {
                $handle = @fopen($candidate, 'x+b');
            } finally {
                umask($mask);
            }
            if ($handle === false) {
                throw new RuntimeException('Cannot create a private plaintext staging file in the output directory.');
            }
            $path = $candidate;
            $output = $handle;
        } finally {
            if ($signalMask !== null) {
                pcntl_sigprocmask(SIG_SETMASK, $signalMask);
            }
        }
        if (!@chmod($path, 0600) || (fstat($output)['mode'] & 0777) !== 0600) {
            throw new RuntimeException('Cannot ensure owner-only permissions for the decrypted ZIP.');
        }
    }

    private static function assertLocalPath(string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i', $path)) {
            throw new RuntimeException('Source and output must be local file paths.');
        }
    }

    private static function assertNoSymlinks(string $path): void
    {
        $absolute = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : getcwd() . DIRECTORY_SEPARATOR . $path;
        $parts = explode(DIRECTORY_SEPARATOR, $absolute);
        $current = '';
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $current .= DIRECTORY_SEPARATOR . $part;
            clearstatcache(true, $current);
            if (is_link($current)) {
                throw new RuntimeException('Source and output paths must not contain symbolic links.');
            }
        }
    }

    private static function erasePassword(#[SensitiveParameter] ?string &$password): void
    {
        if ($password !== null && function_exists('sodium_memzero')) {
            sodium_memzero($password);
        }
        $password = null;
    }
}
