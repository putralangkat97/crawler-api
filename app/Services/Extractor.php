<?php

namespace App\Services;

use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use League\HTMLToMarkdown\HtmlConverter;
use Symfony\Component\DomCrawler\Crawler;

class Extractor
{
    public function extract(string $html, string $url, string $returnFormat): array
    {
        $readability = new Readability($this->readabilityConfig());
        $content = $html;
        $title = '';
        $description = '';

        try {
            $readability->parse($html);
            $content = $readability->getContent();
            $title = $readability->getTitle() ?? '';
            $description = $readability->getExcerpt() ?? '';
        } catch (ParseException) {
            // fallback to raw html
        }

        $crawler = new Crawler($html, $url);
        $links = $crawler->filter('a[href]')->each(function (Crawler $node) {
            return $node->attr('href');
        });

        $images = $crawler->filter('img[src]')->each(function (Crawler $node) {
            return $node->attr('src');
        });

        $converter = new HtmlConverter;
        $text = $returnFormat === 'raw' ? $html : $converter->convert($content);

        if ($returnFormat === 'text') {
            $text = strip_tags($content);
        }

        if ($returnFormat === 'xml') {
            $text = $html;
        }

        return [
            'content' => $text,
            'title' => $title,
            'description' => $description,
            'links' => array_values(array_filter($links)),
            'images' => array_values(array_filter($images)),
            'metadata' => [
                'title' => $title,
                'description' => $description,
            ],
        ];
    }

    public function extractLinks(string $html, string $url): array
    {
        $crawler = new Crawler($html, $url);

        return $crawler->filter('a[href]')->each(function (Crawler $node) {
            return $node->attr('href');
        });
    }

    protected function readabilityConfig(): Configuration
    {
        $config = new Configuration;
        $config->setFixRelativeURLs(true);
        $config->setOriginalURL('');
        $config->setSummonCthulhu(false);
        $config->setNormalizeEntities(true);

        return $config;
    }
}
