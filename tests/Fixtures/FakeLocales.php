<?php

declare(strict_types=1);

namespace Bolt\SitemapExtension\Tests\Fixtures;

/**
 * Stand-in for a Bolt locales collection. The template both iterates it
 * (`for locale in record.definition.locales`) and reads `.all` for its length
 * (`record.definition.locales.all|length`).
 *
 * @implements \IteratorAggregate<int, string>
 */
final class FakeLocales implements \IteratorAggregate
{
    /**
     * @param string[] $locales
     */
    public function __construct(private readonly array $locales)
    {
    }

    /**
     * @return string[]
     */
    public function all(): array
    {
        return $this->locales;
    }

    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->locales);
    }
}