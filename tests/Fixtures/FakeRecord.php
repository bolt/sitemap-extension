<?php

declare(strict_types=1);

namespace Bolt\SitemapExtension\Tests\Fixtures;

/**
 * Minimal stand-in for a Bolt content record, exposing only the properties the
 * sitemap template reads: `definition`, `modifiedAt`, `image`, plus the values
 * the stubbed `|link` / `|image` filters return.
 */
final class FakeRecord
{
    /**
     * @param array<string, string> $localeLinks locale => link, used by `record|link(true, locale)`
     */
    public function __construct(
        public string $link,
        public FakeContentType $definition,
        public ?\DateTimeInterface $modifiedAt = null,
        public ?FakeImage $image = null,
        public string $imagePath = '',
        public array $localeLinks = [],
    ) {
    }

    public function linkFor(?string $locale): string
    {
        if ($locale === null) {
            return $this->link;
        }

        return $this->localeLinks[$locale] ?? $this->link;
    }
}