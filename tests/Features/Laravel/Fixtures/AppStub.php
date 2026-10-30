<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Tests\Features\Laravel\Fixtures;

use Illuminate\Container\Container;

/** Só o que o provider chama do `Application`. */
final class AppStub extends Container
{
    public function __construct(private readonly bool $console = true)
    {
    }

    public function runningInConsole(): bool
    {
        return $this->console;
    }

    public function basePath(string $path = ''): string
    {
        return sys_get_temp_dir().'/pzofx-test/'.ltrim($path, '/');
    }
}
