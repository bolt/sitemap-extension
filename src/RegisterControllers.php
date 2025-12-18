<?php

declare(strict_types=1);

namespace Bolt\SitemapExtension;

use Symfony\Component\Routing\Route;

class RegisterControllers
{
    /**
     * @return array<string, Route>
     */
    public static function getRoutes(): array
    {
        return [
            'xml_sitemap' => new Route(
                '/sitemap.xml',
                ['_controller' => 'Bolt\SitemapExtension\Controller::sitemap']
            ),
            'xml_sitemap_xsl' => new Route(
                '/sitemap.xsl',
                ['_controller' => 'Bolt\SitemapExtension\Controller::xsl']
            ),
        ];
    }
}
