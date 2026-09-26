<?php

declare(strict_types=1);

/*
 * Writes spec/fixtures.json from this implementation. The JavaScript and Python tests read it,
 * and a PHP test fails when it is out of date.
 */

use Tests\Fixtures;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

file_put_contents(Fixtures::path(), Fixtures::encode(Fixtures::make()));
echo 'wrote ', Fixtures::path(), "\n";
