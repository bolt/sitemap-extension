<?php

declare(strict_types=1);

namespace Bolt\SitemapExtension;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Collects static (template-only) routes that should be listed in the sitemap.
 *
 * A "static route" is any route handled by Bolt's TemplateController that can
 * be generated without parameters — typically the entries defined in
 * `config/routes.yaml`. Routes that only differ by a `{_locale}` parameter are
 * treated as locale variants of the same template and surfaced as `hreflang`
 * alternates rather than as separate entries.
 *
 * When disabled (the `static_routes` config option is off), collecting yields
 * an empty list.
 *
 * The collector is intentionally free of any framework/container coupling so it
 * can be unit-tested with a hand-built RouteCollection.
 */
final class StaticRouteCollector
{
    public const DEFAULT_TEMPLATE_CONTROLLER = 'Bolt\Controller\Frontend\TemplateController::template';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RouteCollection $routeCollection,
        private readonly string $defaultLocale,
        private readonly bool $enabled = true,
        private readonly string $templateController = self::DEFAULT_TEMPLATE_CONTROLLER,
    ) {
    }

    /**
     * @param string[] $exclude route names to skip
     *
     * @return array<int, array{name: string, loc: string, templateName: ?string, alternates: array<string, string>}>
     */
    public function collect(array $exclude = []): array
    {
        if (! $this->enabled) {
            return [];
        }

        $localeVariants = $this->groupLocaleVariants();

        $result = [];
        foreach ($this->routeCollection->all() as $name => $route) {
            if (! $this->isTemplateRoute($route->getDefault('_controller'))) {
                continue;
            }
            // Only routes that can be generated without parameters; the
            // {_locale} variants are surfaced as alternates instead.
            // getVariables() covers both path and host variables, so routes
            // with a host placeholder are skipped rather than throwing when
            // generated without arguments.
            if ($route->compile()->getVariables() !== []) {
                continue;
            }
            if (in_array($name, $exclude, true)) {
                continue;
            }

            $rawTemplate = $route->getDefault('templateName');
            $templateName = is_string($rawTemplate) ? $rawTemplate : null;
            $loc = $this->urlGenerator->generate($name, [], UrlGeneratorInterface::ABSOLUTE_URL);

            $result[] = [
                'name' => $name,
                'loc' => $loc,
                'templateName' => $templateName,
                'alternates' => $this->buildAlternates(
                    $templateName !== null ? ($localeVariants[$templateName] ?? []) : [],
                    $loc
                ),
            ];
        }

        return $result;
    }

    /**
     * Group {_locale}-only variants by their template name, so they can be
     * attached as hreflang alternates to the canonical route.
     *
     * @return array<string, array<string, string>> templateName => [routeName => localeRequirement]
     */
    private function groupLocaleVariants(): array
    {
        $variants = [];
        foreach ($this->routeCollection->all() as $name => $route) {
            if (! $this->isTemplateRoute($route->getDefault('_controller'))) {
                continue;
            }
            // `_locale` must be the route's only variable (no host or other
            // path variables), so the variant URL can be generated from just
            // the locale.
            if ($route->compile()->getVariables() !== ['_locale']) {
                continue;
            }

            $templateName = $route->getDefault('templateName');
            if (! is_string($templateName)) {
                continue;
            }

            $variants[$templateName][$name] = (string) $route->getRequirement('_locale');
        }

        return $variants;
    }

    /**
     * Build the hreflang alternates for a route from its locale variants.
     *
     * The default locale points at the canonical (unprefixed) URL rather than
     * its prefixed variant, to avoid advertising a duplicate. When a cluster of
     * alternates exists, the default locale is always included so the canonical
     * page references itself — even if the `{_locale}` requirement only lists
     * the non-default locales (a common setup where the default locale is
     * served without a prefix).
     *
     * The `{_locale}` requirement is expected to be a plain pipe-separated list
     * of locales (e.g. "en|nl|de"), as produced by Bolt's `%app_locales%`. More
     * elaborate regular-expression requirements are not parsed.
     *
     * @param array<string, string> $variants    routeName => localeRequirement ("en|nl|…")
     * @param string                $canonicalUrl the default-locale URL
     *
     * @return array<string, string> locale => URL
     */
    private function buildAlternates(array $variants, string $canonicalUrl): array
    {
        $alternates = [];
        foreach ($variants as $variantName => $localeRequirement) {
            foreach ($localeRequirement !== '' ? explode('|', $localeRequirement) : [] as $locale) {
                $alternates[$locale] = $locale === $this->defaultLocale
                    ? $canonicalUrl
                    : $this->urlGenerator->generate(
                        $variantName,
                        ['_locale' => $locale],
                        UrlGeneratorInterface::ABSOLUTE_URL
                    );
            }
        }

        // Ensure the canonical (default-locale) page references itself, so the
        // hreflang cluster is complete even when the {_locale} requirement does
        // not list the default locale.
        if ($alternates !== [] && $this->defaultLocale !== '' && ! isset($alternates[$this->defaultLocale])) {
            $alternates[$this->defaultLocale] = $canonicalUrl;
        }

        return $alternates;
    }

    private function isTemplateRoute(mixed $controller): bool
    {
        return $controller === $this->templateController;
    }
}
