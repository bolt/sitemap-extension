<?php

declare(strict_types=1);

namespace Bolt\SitemapExtension;

use Bolt\Entity\Content;
use Bolt\Entity\Taxonomy;
use Bolt\Extension\ExtensionController;
use Bolt\Repository\TaxonomyRepository;
use Illuminate\Support\Collection;
use Pagerfanta\PagerfantaInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

class Controller extends ExtensionController
{
    public function sitemap(): Response
    {
        $config = $this->getConfig();
        $showListings = $config->get('show_listings');
        $excludeContentTypes = $config->get('exclude_contenttypes', []);
        $excludeListings = $config->get('exclude_listings', []);
        $contentTypes = $this->boltConfig->get('contenttypes')->where('viewless', false)->keys()->implode(',');
        $records = $this->createPager($contentTypes, $config['limit']);

        $context = [
            'title' => 'Sitemap',
            'records' => $records,
            'showListings' => $showListings,
            'excludeContentTypes' => $excludeContentTypes,
            'excludeListings' => $excludeListings,
            'staticRoutes' => $this->collectStaticRoutes($config),
        ];
        if (isset($config['taxonomies']) && is_array($config['taxonomies'])) {
            $taxonomyRecords = [];

            /** @var TaxonomyRepository $taxonomyRepository */
            $taxonomyRepository = $this->entityManager->getRepository(Taxonomy::class);

            /** @var string $taxonomy */
            foreach ($config['taxonomies'] as $taxonomy) {
                $taxonomyRecords = array_merge($taxonomyRecords, $taxonomyRepository->findBy(['type' => $taxonomy]));
            }

            $context['taxonomies'] = $taxonomyRecords;
        }

        $headerContentType = 'text/xml;charset=UTF-8';
        $view = $config['templates']['xml'] ?? '@sitemap/sitemap.xml.twig';
        $response = $this->render($view, $context);
        $response->headers->set('Content-Type', $headerContentType);

        return $response;
    }

    public function xsl(): Response
    {
        $headerContentType = 'text/xml;charset=UTF-8';

        $config = $this->getConfig();
        $view = $config['templates']['xsl'] ?? '@sitemap/sitemap.xsl';

        $response = $this->render($view);
        $response->headers->set('Content-Type', $headerContentType);

        return $response;
    }

    /**
     * @return Content|PagerfantaInterface<Content>|null
     */
    private function createPager(string $contentType, int $pageSize)
    {
        $params = [
            'status' => 'published',
            'returnmultiple' => true,
            'order' => 'id',
        ];

        $records = $this->query->getContentForTwig($contentType, $params);
        if ($records instanceof PagerfantaInterface) {
            $records->setMaxPerPage($pageSize)->setCurrentPage(1);
        }

        return $records;
    }

    /**
     * Collect static (template-only) routes for inclusion in the sitemap.
     *
     * When `static_routes` is enabled, every route handled by Bolt's
     * TemplateController that can be generated without parameters is added.
     * `<lastmod>` is taken from the modification time of the route's template
     * file, and locale variants (routes for the same template that only differ
     * by a `{_locale}` parameter) are emitted as `hreflang` alternates.
     *
     * @param Collection<array-key, mixed> $config
     *
     * @return array<int, array{loc: string, lastmod: ?\DateTimeInterface, alternates: array<string, string>}>
     */
    private function collectStaticRoutes(Collection $config): array
    {
        /** @var RouterInterface $router */
        $router = $this->container->get('router');
        $defaultLocale = $this->getParameter('kernel.default_locale');

        $collector = new StaticRouteCollector(
            $router,
            $router->getRouteCollection(),
            is_string($defaultLocale) ? $defaultLocale : '',
            (bool) $config->get('static_routes', false)
        );

        $result = [];
        foreach ($collector->collect((array) $config->get('exclude_static_routes', [])) as $entry) {
            $result[] = [
                'loc' => $entry['loc'],
                'lastmod' => $this->resolveTemplateLastmod($entry['templateName']),
                'alternates' => $entry['alternates'],
            ];
        }

        return $result;
    }

    /**
     * Resolve the last-modified time of a static route's template from the
     * template file's mtime. The template name is resolved relative to the
     * active theme directory, which is where TemplateController templates live.
     */
    private function resolveTemplateLastmod(?string $template): ?\DateTimeInterface
    {
        if ($template === null || $template === '') {
            return null;
        }

        $path = $this->boltConfig->getPath('theme', true, $template);

        if (! is_file($path)) {
            return null;
        }

        $mtime = filemtime($path);
        if ($mtime === false) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp($mtime);
    }
}
