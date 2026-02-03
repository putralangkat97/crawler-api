<?php

namespace App\Services;

use Symfony\Component\DomCrawler\UriResolver;

class UrlNormalizer
{
    public function normalize(string $url): string
    {
        $parts = parse_url($url);
        if (! $parts || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        $normalized = sprintf('%s://%s%s%s', $scheme, $host, $path, $query);

        return preg_replace('/#.*$/', '', $normalized);
    }

    public function resolve(string $baseUrl, string $relative): string
    {
        return UriResolver::resolve($relative, $baseUrl);
    }
}
