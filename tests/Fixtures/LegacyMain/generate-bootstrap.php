<?php

// This loader deliberately resolves Capsule classes only from the pinned git export.
$loader = require dirname(__DIR__, 3) . '/vendor/autoload.php';
$source = getenv('CAPSULE_LEGACY_SOURCE');
if (!$source || !is_dir($source . '/src')) {
    throw new RuntimeException('Set CAPSULE_LEGACY_SOURCE to the exported historical source tree.');
}
$loader->setPsr4('Dgtlss\\Capsule\\', [$source . '/src']);
