<?php

namespace App\Services;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Str;

class ImageExtractor
{
    /**
     * Extract relevant images from multiple URLs
     */
    public function extractFromUrls(array $urls, ?int $concurrency = null, ?bool $blockGifs = null, ?string $prompt = null, ?bool $promptFilter = null): array
    {
        $concurrency = $concurrency ?? config('scraper.concurrency', 5);
        $blockGifs = $blockGifs ?? config('scraper.block_gifs', true);
        $promptFilter = $promptFilter ?? config('scraper.prompt_filter_enabled', true);

        $responses = Http::pool(fn (Pool $pool) => 
            collect($urls)->map(fn($url) => $pool->as($url)->get($url))->all()
        );

        $results = [];

        foreach ($responses as $url => $response) {
            if (!$response->successful()) {
                continue;
            }

            $images = $this->extractFromHtml($response->body(), $url, $blockGifs, $prompt, $promptFilter);
            
            $results[] = [
                'url' => $url,
                'images' => $images,
                'count' => count($images),
            ];
        }

        return $results;
    }

    protected function extractFromHtml(string $html, string $base_url, bool $blockGifs = true, ?string $prompt = null, bool $promptFilter = true): array
    {
        $crawler = new Crawler($html, $base_url);

        $page_title = '';
        $title_nodes = $crawler->filter('title');
        if ($title_nodes->count() > 0) {
            $page_title = trim($title_nodes->first()->text());
        }

        $meta_title = '';
        $meta_title_nodes = $crawler->filter('meta[property="og:title"], meta[name="twitter:title"]');
        if ($meta_title_nodes->count() > 0) {
            $meta_title = trim($meta_title_nodes->first()->attr('content') ?? '');
        }

        $page_context = trim($meta_title . ' ' . $page_title);

        // 1. Prioritas tinggi: og:image, twitter:image
        $social_images = $crawler->filter('meta[property="og:image"], meta[name="twitter:image"]')
            ->each(function (Crawler $node) use ($base_url) {
                $content = $node->attr('content');
                return $content ? UriResolver::resolve($content, $base_url) : null;
            });

        // 2. img tags
        $img_images = $crawler->filter('img')->each(function (Crawler $node) use ($base_url) {
            $src = $node->attr('src') ?? $node->attr('data-src') ?? $node->attr('data-lazy-src');
            
            if (!$src) {
                return null;
            }

            return [
                'url' => UriResolver::resolve($src, $base_url),
                'context' => trim(implode(' ', array_filter([
                    $node->attr('alt'),
                    $node->attr('title'),
                    $node->attr('aria-label'),
                    $node->attr('data-alt'),
                ]))),
            ];
        });

        // Gabung semua
        $all_images = [];
        foreach ($social_images as $image_url) {
            if (!$image_url) {
                continue;
            }
            $all_images[$image_url] = $page_context;
        }
        foreach ($img_images as $image) {
            if (!is_array($image) || empty($image['url'])) {
                continue;
            }

            $existing = $all_images[$image['url']] ?? '';
            $context = trim($existing . ' ' . ($image['context'] ?? ''));
            $all_images[$image['url']] = $context;
        }

        // Filter junk + ranking
        $scored_images = [];
        foreach ($all_images as $image_url => $context) {
            $score = $this->scoreImage($image_url, $base_url, $blockGifs, $prompt, $context, $promptFilter);
            
            if ($score > 0) { // skip junk (score 0)
                $scored_images[] = [
                    'url' => $image_url,
                    'score' => $score,
                ];
            }
        }

        // Sort by score desc, ambil top N
        usort($scored_images, fn($a, $b) => $b['score'] <=> $a['score']);
        
        return array_slice(
            array_column($scored_images, 'url'), 
            0, config('scraper.max_images_per_url', 5)
        );
    }

    /**
     * Score image relevancy (higher = better)
     */
    protected function scoreImage(string $url, string $base_url, bool $blockGifs = true, ?string $prompt = null, string $context = '', bool $promptFilter = true): int
    {
        $parsed = parse_url($url);
        $host = strtolower($parsed['host'] ?? '');
        $path = $parsed['path'] ?? '';
        $query = $parsed['query'] ?? '';

        $score = 1; // base score

        // Skip total junk
        if ($this->isJunkImage($url, $host, $path, $blockGifs)) {
            return 0;
        }

        // Filter by query string size parameters (e.g., ?w=40&h=40)
        if ($query) {
            parse_str($query, $params);
            if (isset($params['w']) && (int)$params['w'] <= 150) {
                return 0;
            }
            if (isset($params['h']) && (int)$params['h'] <= 150) {
                return 0;
            }
        }

        // Prompt filtering (only keep images matching prompt keywords)
        if ($promptFilter) {
            $prompt_keywords = $this->promptKeywords($prompt);
            if (!empty($prompt_keywords)) {
                $haystack = strtolower($url . ' ' . $context);
                $matches = 0;
                foreach ($prompt_keywords as $keyword) {
                    if (str_contains($haystack, $keyword)) {
                        $matches++;
                    }
                }

                if ($matches === 0) {
                    return 0;
                }

                $score += min(12, $matches * 3);
            }
        }

        // Bonus untuk social images (sudah diresolve sebelumnya)
        if (str_contains($url, 'og:image') || str_contains($url, 'twitter:image')) {
            $score += 20;
        }

        // Bonus untuk gambar di domain sama dengan baseUrl (kemungkinan konten utama)
        $base_host = parse_url($base_url, PHP_URL_HOST);
        if (str_contains($host, $base_host)) {
            $score += 10;
        }

        // Bonus ukuran besar (landscape hero images)
        if (preg_match('/-(\d{3,})[-x](\d{2,})\./', $path, $matches)) {
            $width = (int) $matches[1];
            $height = (int) $matches[2];
            if ($width > 600 || $height > 400) {
                $score += 8;
            }
            
            // Penalize extreme aspect ratios (stretched banners/spacers)
            if ($height > 0) {
                $ratio = $width / $height;
                if ($ratio > 15 || $ratio < 0.2) {
                    $score -= 10;
                }
            }
        }

        // Malus untuk thumbnail kecil
        if (preg_match('/-(100|120|150|200|250)[-x]\d+\./i', $path)) {
            $score -= 5;
        }

        // Bonus untuk format gambar utama
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $score += 3;
        }

        return max(0, $score);
    }

    protected function promptKeywords(?string $prompt): array
    {
        if (!$prompt) {
            return [];
        }

        $normalized = strtolower(trim($prompt));
        if ($normalized === '') {
            return [];
        }

        $stopwords = [
            'the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'is', 'are', 'was', 'were',
            'who', 'what', 'when', 'where', 'why', 'how', 'yang', 'dan', 'atau', 'dari',
            'di', 'ke', 'untuk', 'pada', 'adalah', 'itu', 'ini', 'sebagai',
        ];

        $tokens = preg_split('/[^a-z0-9]+/i', $normalized) ?: [];
        $keywords = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '' || strlen($token) < 3) {
                continue;
            }
            if (in_array($token, $stopwords, true)) {
                continue;
            }
            $keywords[$token] = true;
        }

        return array_keys($keywords);
    }

    /**
     * Hard blacklist untuk junk images
     */
    protected function isJunkImage(string $url, string $host, string $path, bool $blockGifs = true): bool
    {
        // data URI (base64)
        if (str_starts_with($url, 'data:')) {
            return true;
        }

        // tracking/analytics
        $junk_hosts = [
            'scorecardresearch.com',
            'googletagmanager.com',
            'google-analytics.com',
            'doubleclick.net',
        ];
        foreach ($junk_hosts as $junk_host) {
            if (str_contains($host, $junk_host)) {
                return true;
            }
        }

        // flags, icons, no-image, products, merchants, etc.
        $junk_paths = [
            '/flags/',
            '/flexiimages/',
            'no-image-available',
            '/amazon-uk-',
            '/badge-',
            '/icon-',
            '/logo-',
            '/svg-sprite',
            '/products/',
            '/merchants/',
            '/infographics/',
            '/misc/',
            '/thumbor/',
            'gambling',
            'certification',
        ];
        foreach ($junk_paths as $junk_path) {
            if (str_contains(strtolower($path), $junk_path)) {
                return true;
            }
        }

        // Logo and favicon files (check filename directly)
        $filename = strtolower(basename($path));
        if (str_contains($filename, 'logo') || str_contains($filename, 'favicon')) {
            return true;
        }

        // Favicon extensions
        if (str_ends_with($filename, '.ico')) {
            return true;
        }

        // SVG icons (kecuali yang besar)
        if (str_ends_with(strtolower($path), '.svg') 
            && !preg_match('/-(\d{3,})[-x](\d{2,})\.svg/', $path)) {
            return true;
        }

        // GIF images (configurable, mostly spacers/loaders)
        if ($blockGifs && str_ends_with(strtolower($path), '.gif')) {
            return true;
        }

        return false;
    }
}
