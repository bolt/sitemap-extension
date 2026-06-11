<?php

declare(strict_types=1);

namespace Bolt\SitemapExtension\Tests\Fixtures;

/**
 * Stand-in for a Bolt ContentType definition (`record.definition` in the
 * template).
 */
final class FakeContentType
{
    public FakeLocales $locales;

    /**
     * @param string[] $locales
     */
    public function __construct(
        public string $slug,
        public bool $viewless_listing = false,
        public bool $hide_listing_from_xml_sitemap = false,
        array $locales = ['en'],
    ) {
        $this->locales = new FakeLocales($locales);
    }
}