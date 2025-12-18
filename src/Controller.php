<?php

declare(strict_types=1);

namespace Bolt\SitemapExtension;

use Bolt\Entity\Content;
use Bolt\Entity\Taxonomy;
use Bolt\Extension\ExtensionController;
use Bolt\Repository\TaxonomyRepository;
use Bolt\Storage\Query;
use Pagerfanta\PagerfantaInterface;
use Symfony\Component\HttpFoundation\Response;

class Controller extends ExtensionController
{
    public function sitemap(): Response
    {
        $config = $this->getConfig();
        $showListings = $config->get('show_listings');
        $excludeContentTypes = $config->get('exclude_contenttypes', []);
        $excludeListings = $config->get('exclude_listings', []);
        $contentTypes = $this->boltConfig->get('contenttypes')->where('viewless', false)->keys()->implode(',');
        $records = $this->createPager($this->query, $contentTypes, $config['limit']);

        $context = [
            'title' => 'Sitemap',
            'records' => $records,
            'showListings' => $showListings,
            'excludeContentTypes' => $excludeContentTypes,
            'excludeListings' => $excludeListings,
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
    private function createPager(Query $query, string $contentType, int $pageSize)
    {
        $params = [
            'status' => 'published',
            'returnmultiple' => true,
            'order' => 'id',
        ];

        $records = $query->getContentForTwig($contentType, $params);
        if ($records instanceof PagerfantaInterface) {
            $records->setMaxPerPage($pageSize)->setCurrentPage(1);
        }

        return $records;
    }
}
