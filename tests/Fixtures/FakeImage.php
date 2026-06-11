<?php

declare(strict_types=1);

namespace Bolt\SitemapExtension\Tests\Fixtures;

/**
 * Stand-in for a Bolt image field (`record.image` in the template), exposing
 * the `alt` attribute used for `<image:title>`.
 */
final class FakeImage
{
    public function __construct(public string $alt = '')
    {
    }
}