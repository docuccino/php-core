<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

/**
 * An object whose `__sleep()` changes it, as a framework model's does when it flushes its caches, noting each
 * call. It answers with what it was built with: the names to keep, or null, which `serialize()` writes as `N;`.
 * Every member is public, so a name mangled from NUL is never what gives it away.
 */
final class SleepingValue
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param  list<string>|null  $keeps
     */
    public function __construct(public readonly ?array $keeps = []) {}

    /**
     * @return list<string>|null
     */
    public function __sleep()
    {
        $this->calls[] = '__sleep';

        return $this->keeps;
    }
}
