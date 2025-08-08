<?php

namespace Dgtlss\Capsule\Support;

class BackupContext
{
    public string $mode; // normal|chunked
    public bool $verbose = false;
    public int $compressionLevel = 1;
    public bool $encryption = false;
    public bool $verification = false;
    public ?string $localArchivePath = null;
    public ?string $remotePath = null;
    public array $metadata = [];

    public function __construct(string $mode, array $metadata = [])
    {
        $this->mode = $mode;
        $this->metadata = $metadata;
    }
}
