<?php

// Desktop entry point (bin/jumphp run). The browser build boots through vendor/jumphp/engine/bootstrap/wasm.php.
require __DIR__ . '/vendor/autoload.php';

exit(JumPHP\Kernel\Application::boot(__DIR__)->run());
