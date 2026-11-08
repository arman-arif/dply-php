<?php

declare(strict_types=1);

// Entry point — must stay the last module.
if (PHP_SAPI === 'cli') {
    exit(cli_main($argv));
}
web_main();
