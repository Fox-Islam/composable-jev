<?php

declare(strict_types=1);

use Tests\Fixtures;

it('keeps spec/fixtures.json in step with this implementation', function (): void {
    expect(file_get_contents(Fixtures::path()))->toBe(Fixtures::encode(Fixtures::make()), 'run composer fixtures');
});
