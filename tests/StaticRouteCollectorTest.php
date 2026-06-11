<?php

declare(strict_types=1);

namespace Bolt\SitemapExtension\Tests;

use Bolt\SitemapExtension\StaticRouteCollector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class StaticRouteCollectorTest extends TestCase
{
    #[Test]
    public function it_collects_a_template_route_with_an_absolute_loc(): void
    {
        $routes = new RouteCollection();
        $routes->add('about', $this->templateRoute('/about', 'pages/about.twig'));

        $result = $this->collector($routes)->collect();

        self::assertCount(1, $result);
        self::assertSame('about', $result[0]['name']);
        self::assertSame('https://example.com/about', $result[0]['loc']);
        self::assertSame('pages/about.twig', $result[0]['templateName']);
        self::assertSame([], $result[0]['alternates']);
    }

    #[Test]
    public function it_skips_routes_with_a_different_controller(): void
    {
        $routes = new RouteCollection();
        $routes->add('blog', new Route('/blog', ['_controller' => 'App\Controller\BlogController::index']));

        self::assertSame([], $this->collector($routes)->collect());
    }

    #[Test]
    public function it_skips_routes_that_require_parameters(): void
    {
        $routes = new RouteCollection();
        $routes->add('article', $this->templateRoute('/article/{slug}', 'pages/article.twig'));

        self::assertSame([], $this->collector($routes)->collect());
    }

    #[Test]
    public function it_skips_routes_with_a_host_variable_without_throwing(): void
    {
        // A host placeholder is not a path variable, so it must still be
        // skipped — otherwise generating the URL without the host argument
        // would throw and take down the whole sitemap.
        $routes = new RouteCollection();
        $hostRoute = $this->templateRoute('/landing', 'pages/landing.twig');
        $hostRoute->setHost('{sub}.example.com');
        $routes->add('landing', $hostRoute);
        $routes->add('about', $this->templateRoute('/about', 'pages/about.twig'));

        $result = $this->collector($routes)->collect();

        self::assertCount(1, $result);
        self::assertSame('about', $result[0]['name']);
    }

    #[Test]
    public function it_excludes_routes_listed_by_name(): void
    {
        $routes = new RouteCollection();
        $routes->add('about', $this->templateRoute('/about', 'pages/about.twig'));
        $routes->add('contact', $this->templateRoute('/contact', 'pages/contact.twig'));

        $result = $this->collector($routes)->collect(['contact']);

        self::assertCount(1, $result);
        self::assertSame('about', $result[0]['name']);
    }

    #[Test]
    public function it_builds_hreflang_alternates_from_locale_variants(): void
    {
        $routes = new RouteCollection();
        $routes->add('about', $this->templateRoute('/about', 'pages/about.twig'));
        $routes->add('about_locale', $this->templateRoute(
            '/{_locale}/about',
            'pages/about.twig',
            ['_locale' => 'en|de']
        ));

        $result = $this->collector($routes, 'en')->collect();

        self::assertCount(1, $result);
        self::assertSame([
            // default locale points at the canonical (unprefixed) URL
            'en' => 'https://example.com/about',
            'de' => 'https://example.com/de/about',
        ], $result[0]['alternates']);
    }

    #[Test]
    public function it_adds_a_self_reference_when_the_default_locale_is_not_in_the_requirement(): void
    {
        // Common setup: the default locale is served unprefixed and has no
        // {_locale} route, so only the non-default locales are listed.
        $routes = new RouteCollection();
        $routes->add('about', $this->templateRoute('/about', 'pages/about.twig'));
        $routes->add('about_locale', $this->templateRoute(
            '/{_locale}/about',
            'pages/about.twig',
            ['_locale' => 'de|fr']
        ));

        $result = $this->collector($routes, 'en')->collect();

        self::assertCount(1, $result);
        self::assertSame([
            'de' => 'https://example.com/de/about',
            'fr' => 'https://example.com/fr/about',
            // the canonical/default-locale page references itself
            'en' => 'https://example.com/about',
        ], $result[0]['alternates']);
    }

    #[Test]
    public function it_does_not_emit_a_locale_variant_as_its_own_entry(): void
    {
        $routes = new RouteCollection();
        // Only the {_locale} variant exists, with no canonical counterpart.
        $routes->add('about_locale', $this->templateRoute(
            '/{_locale}/about',
            'pages/about.twig',
            ['_locale' => 'en|de']
        ));

        self::assertSame([], $this->collector($routes)->collect());
    }

    #[Test]
    public function it_returns_no_alternates_when_there_is_no_locale_variant(): void
    {
        $routes = new RouteCollection();
        $routes->add('about', $this->templateRoute('/about', 'pages/about.twig'));

        $result = $this->collector($routes)->collect();

        self::assertSame([], $result[0]['alternates']);
    }

    #[Test]
    public function it_respects_a_custom_template_controller(): void
    {
        $routes = new RouteCollection();
        $routes->add('about', new Route('/about', [
            '_controller' => 'App\Controller\CustomController::render',
            'templateName' => 'pages/about.twig',
        ]));

        $context = (new RequestContext())->setHost('example.com')->setScheme('https');
        $collector = new StaticRouteCollector(
            new UrlGenerator($routes, $context),
            $routes,
            'en',
            templateController: 'App\Controller\CustomController::render'
        );

        $result = $collector->collect();

        self::assertCount(1, $result);
        self::assertSame('https://example.com/about', $result[0]['loc']);
    }

    #[Test]
    public function it_returns_an_empty_list_when_disabled(): void
    {
        $routes = new RouteCollection();
        $routes->add('about', $this->templateRoute('/about', 'pages/about.twig'));

        self::assertSame([], $this->collector($routes, enabled: false)->collect());
    }

    /**
     * @param array<string, string> $requirements
     */
    private function templateRoute(string $path, string $template, array $requirements = []): Route
    {
        return new Route(
            $path,
            [
                '_controller' => StaticRouteCollector::DEFAULT_TEMPLATE_CONTROLLER,
                'templateName' => $template,
            ],
            $requirements
        );
    }

    private function collector(RouteCollection $routes, string $defaultLocale = 'en', bool $enabled = true): StaticRouteCollector
    {
        $context = (new RequestContext())->setHost('example.com')->setScheme('https');

        return new StaticRouteCollector(new UrlGenerator($routes, $context), $routes, $defaultLocale, $enabled);
    }
}
