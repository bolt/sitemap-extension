<?php

declare(strict_types=1);

namespace Bolt\SitemapExtension\Tests;

use Bolt\SitemapExtension\Tests\Fixtures\FakeContentType;
use Bolt\SitemapExtension\Tests\Fixtures\FakeImage;
use Bolt\SitemapExtension\Tests\Fixtures\FakeRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Integration test for templates/sitemap.xml.twig.
 *
 * The template is rendered through a standalone Twig environment in which the
 * Bolt-specific helpers it relies on (`path`, `absolute_url`, the `|link` and
 * `|image` filters) are replaced by faithful stubs, and records are simple
 * fixtures. This exercises the template's output/branching logic without
 * needing to boot a Bolt kernel.
 */
final class SitemapTemplateTest extends TestCase
{
    private const HOST = 'https://example.com';

    #[Test]
    public function it_renders_a_content_record_url(): void
    {
        $record = new FakeRecord(
            '/entry/about',
            new FakeContentType('pages'),
            new \DateTimeImmutable('2024-01-02T03:04:05+00:00'),
        );

        $xml = $this->render(['records' => [$record], 'showListings' => false]);

        self::assertStringContainsString('<loc>https://example.com/entry/about</loc>', $xml);
        self::assertStringContainsString('<priority>0.8</priority>', $xml);
        self::assertStringContainsString('<lastmod>2024-01-02T03:04:05+00:00</lastmod>', $xml);
        self::assertStringContainsString('<changefreq>weekly</changefreq>', $xml);
    }

    #[Test]
    public function it_renders_the_listing_url_before_the_record_when_listings_are_enabled(): void
    {
        $record = new FakeRecord('/entry/about', new FakeContentType('pages'));

        $xml = $this->render(['records' => [$record], 'showListings' => true]);

        self::assertStringContainsString('<loc>https://example.com/pages</loc>', $xml);
        self::assertStringContainsString('<loc>https://example.com/entry/about</loc>', $xml);
        self::assertLessThan(
            mb_strpos($xml, '/entry/about'),
            mb_strpos($xml, '<loc>https://example.com/pages</loc>'),
            'The listing URL should be emitted before the record URL.'
        );
    }

    #[Test]
    public function it_emits_the_listing_only_once_per_content_type(): void
    {
        $type = new FakeContentType('pages');
        $records = [
            new FakeRecord('/entry/one', $type),
            new FakeRecord('/entry/two', $type),
        ];

        $xml = $this->render(['records' => $records, 'showListings' => true]);

        self::assertSame(1, mb_substr_count($xml, '<loc>https://example.com/pages</loc>'));
        self::assertStringContainsString('/entry/one', $xml);
        self::assertStringContainsString('/entry/two', $xml);
    }

    #[Test]
    public function it_skips_the_listing_for_a_content_type_in_exclude_listings(): void
    {
        $record = new FakeRecord('/entry/about', new FakeContentType('pages'));

        $xml = $this->render([
            'records' => [$record],
            'showListings' => true,
            'excludeListings' => ['pages'],
        ]);

        self::assertStringNotContainsString('<loc>https://example.com/pages</loc>', $xml);
        // The record itself is still listed.
        self::assertStringContainsString('<loc>https://example.com/entry/about</loc>', $xml);
    }

    #[Test]
    public function it_skips_the_record_for_a_content_type_in_exclude_contenttypes(): void
    {
        $record = new FakeRecord('/entry/about', new FakeContentType('pages'));

        $xml = $this->render([
            'records' => [$record],
            'showListings' => true,
            'excludeContentTypes' => ['pages'],
        ]);

        self::assertStringNotContainsString('<loc>https://example.com/entry/about</loc>', $xml);
        // The listing is still emitted.
        self::assertStringContainsString('<loc>https://example.com/pages</loc>', $xml);
    }

    #[Test]
    public function it_skips_the_listing_for_a_viewless_listing_content_type(): void
    {
        $record = new FakeRecord('/entry/about', new FakeContentType('pages', viewless_listing: true));

        $xml = $this->render(['records' => [$record], 'showListings' => true]);

        self::assertStringNotContainsString('<loc>https://example.com/pages</loc>', $xml);
        self::assertStringContainsString('<loc>https://example.com/entry/about</loc>', $xml);
    }

    #[Test]
    public function it_skips_the_listing_when_hidden_from_the_xml_sitemap(): void
    {
        $record = new FakeRecord('/entry/about', new FakeContentType('pages', hide_listing_from_xml_sitemap: true));

        $xml = $this->render(['records' => [$record], 'showListings' => true]);

        self::assertStringNotContainsString('<loc>https://example.com/pages</loc>', $xml);
    }

    #[Test]
    public function it_does_not_render_listings_when_disabled(): void
    {
        $record = new FakeRecord('/entry/about', new FakeContentType('pages'));

        $xml = $this->render(['records' => [$record], 'showListings' => false]);

        self::assertStringNotContainsString('<loc>https://example.com/pages</loc>', $xml);
    }

    #[Test]
    public function it_skips_records_with_an_empty_link(): void
    {
        $record = new FakeRecord('', new FakeContentType('pages'));

        $xml = $this->render(['records' => [$record], 'showListings' => false]);

        self::assertSame(0, mb_substr_count($xml, '<url>'));
    }

    #[Test]
    public function it_uses_priority_one_for_the_homepage(): void
    {
        $record = new FakeRecord('/', new FakeContentType('homepage'));

        $xml = $this->render(['records' => [$record], 'showListings' => false]);

        self::assertStringContainsString('<priority>1</priority>', $xml);
        self::assertStringNotContainsString('<priority>0.8</priority>', $xml);
    }

    #[Test]
    public function it_renders_hreflang_alternates_for_a_multilingual_record(): void
    {
        $record = new FakeRecord(
            '/entry/about',
            new FakeContentType('pages', locales: ['en', 'de']),
            localeLinks: ['en' => '/en/entry/about', 'de' => '/de/entry/about'],
        );

        $xml = $this->render(['records' => [$record], 'showListings' => false]);

        self::assertStringContainsString('hreflang="en" href="https://example.com/en/entry/about"', $xml);
        self::assertStringContainsString('hreflang="de" href="https://example.com/de/entry/about"', $xml);
    }

    #[Test]
    public function it_does_not_render_hreflang_for_a_single_locale_record(): void
    {
        $record = new FakeRecord('/entry/about', new FakeContentType('pages', locales: ['en']));

        $xml = $this->render(['records' => [$record], 'showListings' => false]);

        self::assertStringNotContainsString('hreflang=', $xml);
    }

    #[Test]
    public function it_renders_an_image_block_for_a_record_with_an_image(): void
    {
        $record = new FakeRecord(
            '/entry/about',
            new FakeContentType('pages'),
            image: new FakeImage('A nice photo'),
            imagePath: '/files/photo.jpg',
        );

        $xml = $this->render(['records' => [$record], 'showListings' => false]);

        self::assertStringContainsString('<image:loc>https://example.com/files/photo.jpg</image:loc>', $xml);
        self::assertStringContainsString('<image:title><![CDATA[A nice photo]]></image:title>', $xml);
    }

    #[Test]
    public function it_does_not_attach_an_image_to_the_listing_url(): void
    {
        $record = new FakeRecord(
            '/entry/about',
            new FakeContentType('pages'),
            image: new FakeImage('A nice photo'),
            imagePath: '/files/photo.jpg',
        );

        $xml = $this->render(['records' => [$record], 'showListings' => true]);

        // Only the record URL carries the image, not the listing URL.
        self::assertSame(1, mb_substr_count($xml, '<image:loc>'));
    }

    #[Test]
    public function it_renders_taxonomy_urls_with_a_lower_priority(): void
    {
        $taxonomy = (object) ['link' => '/category/news'];

        $xml = $this->render([
            'records' => [],
            'showListings' => false,
            'taxonomies' => [$taxonomy],
        ]);

        self::assertStringContainsString('<loc>https://example.com/category/news</loc>', $xml);
        self::assertStringContainsString('<priority>0.7</priority>', $xml);
    }

    #[Test]
    public function it_renders_static_route_entries(): void
    {
        $xml = $this->render([
            'records' => [],
            'showListings' => false,
            'staticRoutes' => [
                [
                    'loc' => 'https://example.com/about',
                    'lastmod' => new \DateTimeImmutable('2024-05-06T07:08:09+00:00'),
                    'alternates' => [
                        'en' => 'https://example.com/about',
                        'de' => 'https://example.com/de/about',
                    ],
                ],
            ],
        ]);

        self::assertStringContainsString('<loc>https://example.com/about</loc>', $xml);
        self::assertStringContainsString('<lastmod>2024-05-06T07:08:09+00:00</lastmod>', $xml);
        self::assertStringContainsString('hreflang="de" href="https://example.com/de/about"', $xml);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(array $context): string
    {
        return $this->twig()->render('sitemap.xml.twig', $context);
    }

    private function twig(): Environment
    {
        $twig = new Environment(
            new FilesystemLoader(__DIR__ . '/../templates'),
            ['strict_variables' => false, 'autoescape' => false]
        );

        // path('listing', { contentTypeSlug: 'pages' }) => '/pages'
        $twig->addFunction(new TwigFunction('path', static function (string $name, array $parameters = []): string {
            return '/' . ($parameters['contentTypeSlug'] ?? $name);
        }));

        $twig->addFunction(new TwigFunction('absolute_url', static function (string $url): string {
            return str_starts_with($url, 'http') ? $url : self::HOST . $url;
        }));

        $twig->addFilter(new TwigFilter('link', static function (mixed $value, bool $canonical = false, ?string $locale = null): string {
            $link = $value instanceof FakeRecord
                ? $value->linkFor($locale)
                : (is_object($value) && isset($value->link) ? (string) $value->link : (string) $value);

            // Bolt's `link` filter returns an absolute URL when the canonical
            // flag is set, which is how the template renders hreflang hrefs.
            return $canonical && $link !== '' ? self::HOST . $link : $link;
        }));

        $twig->addFilter(new TwigFilter('image', static function (mixed $value): string {
            return $value instanceof FakeRecord ? $value->imagePath : '';
        }));

        return $twig;
    }
}