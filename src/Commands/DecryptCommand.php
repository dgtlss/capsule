<?php

namespace Dgtlss\Capsule\Commands;

use Dgtlss\Capsule\Support\BackupDecryption;
use Illuminate\Console\Command;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DecryptCommand extends Command
{
    protected $signature = 'capsule:decrypt
        {source : Local .capsule.enc backup file}
        {--output= : New local ZIP file to create (never overwritten)}
        {--password-stdin : Read one password line from redirected standard input}
        {--format=text : Output format (text|json)}';

    protected $description = 'Decrypt a Capsule .capsule.enc backup into a verified, normal ZIP';

    public function __construct()
    {
        parent::__construct();
        $this->setHelp(BackupDecryption::HELP);
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        // Do not allow Symfony to abbreviate --password into --password-stdin,
        // or echo an accidentally supplied secret in an argument error.
        if (BackupDecryption::rejectPasswordArguments($input, $output)) {
            return self::FAILURE;
        }

        try {
            return parent::run($input, $output);
        } catch (ExceptionInterface) {
            // Binding and validation happen before handle(); never let their
            // original exception text reach Laravel's exception renderer/log.
            return BackupDecryption::reportInvalidArguments($input, $output);
        }
    }

    public function handle(): int
    {
        return BackupDecryption::runConsole($this->input, $this->output);
    }
}
