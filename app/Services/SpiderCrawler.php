<?php

namespace App\Services;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Pool;
use fivefilters\Readability\Readability;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use League\HTMLToMarkdown\HtmlConverter;
use Spatie\Browsershot\Browsershot;
use Spatie\PdfToText\Pdf;

class SpiderCrawler
{
    protected array $headers = [
        'User-Agent' => 'AnggitSpider/1.0 (+mailto:mrdiy@anggit.dev)',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9,id;q=0.8',
        'Cache-Control' => 'no-cache',
        'Pragma' => 'no-cache',
        'Connection' => 'keep-alive',
    ];

    public function scrape(array $params): array
    {
        $url = $params['url'] ?? null;
        $limit = $params['limit'] ?? 1;
        $requestType = $params['request'] ?? 'smart';
        $format = $params['format'] ?? 'markdown';

        if (!$url) {
            return ['success' => false, 'error' => 'URL required', 'data' => []];
        }

        $urls = is_array($url) ? $url : [$url];
        $urls = array_values(array_slice($urls, 0, $limit));

        // 1) Fetch HTML via HTTP (pool)
        $responses = Http::pool(function (Pool $pool) use ($urls) {
            return collect($urls)->map(fn ($u) =>
                $pool->as($u)
                    ->timeout(20)
                    ->connectTimeout(10)
                    ->withHeaders($this->headers)
                    ->get($u)
            )->all();
        });

        $results = [];

        foreach ($responses as $originalUrl => $response) {
            // A) Jika HTTP gagal
            if (!$response->successful()) {
                // Kalau request=chrome, kita bisa coba JS render sebagai fallback
                if ($requestType === 'chrome') {
                    $html = $this->renderJS($originalUrl);
                    if (!$html) {
                        $results[] = [
                            'url' => $originalUrl,
                            'success' => false,
                            'error' => $response->status() . ': ' . $response->reason(),
                        ];
                        continue;
                    }

                    $data = $this->processPage($html, $originalUrl, $format);
                    $results[] = $this->formatSuccess($data);
                    continue;
                }

                $results[] = [
                    'url' => $originalUrl,
                    'success' => false,
                    'error' => $response->status() . ': ' . $response->reason(),
                ];
                continue;
            }

            if ($this->isPdfResponse($response, $originalUrl)) {
                try {
                    $text = $this->pdfToTextFromUrl($originalUrl);

                    $results[] = [
                        'url' => $originalUrl,
                        'success' => true,
                        'title' => basename(parse_url($originalUrl, PHP_URL_PATH) ?? 'document.pdf'),
                        'description' => '',
                        'content' => ($params['format'] ?? 'markdown') === 'markdown'
                            ? $this->pdfTextToMarkdown($text, $originalUrl)
                            : $text,
                        'metadata' => [
                            'content_type' => $response->header('Content-Type'),
                            'source_type' => 'pdf',
                        ],
                        'links' => [],
                        'images' => [],
                    ];
                } catch (\Throwable $e) {
                    $results[] = [
                        'url' => $originalUrl,
                        'success' => false,
                        'error' => 'PDF parse failed: ' . $e->getMessage(),
                    ];
                }
                continue;
            }

            // B) HTTP sukses
            $html = $response->body();

            // smart: render JS hanya jika HTML tampak tidak “berisi” (SPA / blocked / empty)
            if ($requestType === 'smart' && !$this->looksLikeHtmlPage($html)) {
                $jsHtml = $this->renderJS($originalUrl);
                if ($jsHtml) {
                    $html = $jsHtml;
                }
            }

            // chrome: selalu render JS (tapi tetap boleh pakai HTML HTTP sebagai backup jika chrome fail)
            if ($requestType === 'chrome') {
                $jsHtml = $this->renderJS($originalUrl);
                if ($jsHtml) {
                    $html = $jsHtml;
                }
            }

            $data = $this->processPage($html, $originalUrl, $format);
            $results[] = $this->formatSuccess($data);
        }

        return [
            'success' => true,
            'data' => $results,
            'params' => $params,
        ];
    }

    protected function formatSuccess(array $data): array
    {
        return [
            'url' => $data['url'],
            'success' => true,
            'title' => $data['title'],
            'description' => $data['description'],
            'content' => $data['content'],
            'metadata' => $data['metadata'],
            'links' => $data['links'],
            'images' => $data['images'],
        ];
    }

    protected function processPage(string $html, string $url, string $format): array
    {
        // Readability.php (FiveFilters)
        $config = new Configuration();
        $config->setFixRelativeURLs(true);
        $config->setOriginalURL($url);

        $title = '';
        $cleanHtml = '';
        $excerpt = '';

        $isWikipedia = str_contains(strtolower(parse_url($url, PHP_URL_HOST) ?? ''), 'wikipedia.org');

        try {
            $readability = new Readability($config);
            $readability->parse($html);

            $title = (string) $readability->getTitle();
            $excerpt = (string) $readability->getExcerpt();

            $contentHtml = (string) $readability->getContent();
            $cleanHtml = $this->cleanArticleHtml($contentHtml, $url);

            // Fallback khusus Wikipedia kalau hasilnya table-heavy atau terlalu pendek
            if ($isWikipedia) {
                $textLen = strlen(trim(strip_tags($cleanHtml)));
                if ($textLen < 400 || str_contains($contentHtml, 'readabilitydatatable')) {
                    $cleanHtml = $this->extractWikipediaMainHtml($html);
                }
            }
        } catch (ParseException $e) {
            $cleanHtml = $isWikipedia ? $this->extractWikipediaMainHtml($html) : $html;
        }

        $dom = new Crawler($html, $url);

        $description = '';
        $meta = $dom->filter('meta[name="description"], meta[property="og:description"]');
        if ($meta->count()) {
            $description = trim((string) $meta->attr('content'));
        }

        $output = match ($format) {
            'markdown', 'commonmark' => $this->toMarkdown($cleanHtml),
            'text' => trim(preg_replace('/\s+/', ' ', strip_tags($cleanHtml))),
            'raw' => $html,
            default => $cleanHtml,
        };

        $links = $dom->filter('a[href]')->each(function ($node) use ($url) {
            $href = $node->attr('href');
            return $href ? UriResolver::resolve($href, $url) : null;
        });
        $links = array_values(array_filter(array_unique($links)));

        $images = $this->extractImages($dom, $url);
        $images = array_values(array_filter(array_unique($images)));

        return [
            'url' => $url,
            'title' => $title ?: ($dom->filter('title')->count() ? trim($dom->filter('title')->text()) : ''),
            'description' => $description ?: $excerpt,
            'content' => $output,
            'metadata' => [
                'title' => $title,
                'lang' => $dom->filter('html')->count() ? ($dom->filter('html')->attr('lang') ?? 'en') : 'en',
            ],
            'links' => $links,
            'images' => $images,
        ];
    }


    protected function toMarkdown(string $html): string
    {
        $converter = new HtmlConverter([
            'remove_nodes' => 'script style noscript iframe',
        ]);

        $md = $converter->convert($html); // HTML -> Markdown [web:258]
        $md = preg_replace("/\n{3,}/", "\n\n", $md);
        return trim($md);
    }

    protected function renderJS(string $url): ?string
    {
        try {
            return Browsershot::url($url)
                ->userAgent($this->headers['User-Agent']) // Browsershot supports userAgent() [web:231]
                ->waitUntilNetworkIdle()
                ->windowSize(1920, 1080)
                ->noSandbox()
                ->timeout(60)
                ->bodyHtml();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function extractImages(Crawler $dom, string $baseUrl): array
    {
        return $dom->filter('img')->each(function ($node) use ($baseUrl) {
            $src = $node->attr('src') ?? $node->attr('data-src') ?? $node->attr('data-lazy-src');
            if (!$src) return null;
            return UriResolver::resolve($src, $baseUrl);
        });
    }

    /**
     * Heuristic ringan: minimal ada <html atau <body atau <title, supaya smart mode tahu kapan perlu JS.
     */
    protected function looksLikeHtmlPage(string $html): bool
    {
        $h = strtolower($html);
        if (str_contains($h, '<html') || str_contains($h, '<body') || str_contains($h, '<title')) {
            return true;
        }
        return strlen(trim(strip_tags($html))) > 200;
    }

    protected function cleanArticleHtml(string $articleHtml, string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $host = strtolower($host);

        // Default cleaning (untuk semua situs)
        $articleHtml = $this->stripBadNodes($articleHtml, [
            'script', 'style', 'noscript', 'iframe'
        ]);

        // Wikipedia-specific cleaning
        if (str_contains($host, 'wikipedia.org')) {
            $articleHtml = $this->cleanWikipediaHtml($articleHtml);
        }

        return $articleHtml;
    }

    protected function cleanWikipediaHtml(string $html): string
    {
        libxml_use_internal_errors(true);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        // Wrap supaya DOMDocument aman parse fragment
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="__wrap__">'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);

        $xp = new \DOMXPath($dom);

        // Hapus elemen noise utama Wikipedia
        $removeXPaths = [
            "//*[@id='toc']",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' navbox ')]",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' vertical-navbox ')]",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' sidebar ')]",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' mw-editsection ')]",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' reflist ')]",
            "//ol[contains(@class,'references')]",
            "//div[@class='mw-references-wrap']",
            "//sup[contains(@class,'reference') or starts-with(@id,'cite_ref')]",
            "//span[starts-with(@id,'cite_ref')]",

            // Hapus tabel yang jelas “noise”
            "//table[contains(@class,'infobox') or contains(@class,'sidebar') or contains(@class,'navbox') or contains(@class,'vertical-navbox')]",
        ];

        foreach ($removeXPaths as $q) {
            $nodes = $xp->query($q);
            if (!$nodes) continue;
            foreach (iterator_to_array($nodes) as $node) {
                if ($node && $node->parentNode) $node->parentNode->removeChild($node);
            }
        }

        // Strip attribute yang bikin noisy (id/class/style/data-*/typeof/about)
        $stripAttrs = ['class','id','style','typeof','about','resource','rel','data-mw','data-*'];
        $all = $xp->query("//*[@*]");
        if ($all) {
            foreach ($all as $el) {
                if (!$el instanceof \DOMElement) continue;

                // hapus attribute generik
                foreach (iterator_to_array($el->attributes) as $attr) {
                    $name = $attr->nodeName;

                    $isData = str_starts_with($name, 'data-');
                    $isKeep = in_array($name, ['href','src','alt','title'], true); // keep link/image attrs

                    if (!$isKeep && ($isData || in_array($name, ['class','id','style','typeof','about','resource','rel'], true))) {
                        $el->removeAttribute($name);
                    }
                }
            }
        }

        // Ambil innerHTML wrapper
        $wrap = $dom->getElementById('__wrap__');
        $clean = '';
        if ($wrap) {
            foreach ($wrap->childNodes as $child) {
                $clean .= $dom->saveHTML($child);
            }
        }

        libxml_clear_errors();
        return $clean;
    }

    protected function stripBadNodes(string $html, array $tags): string
    {
        libxml_use_internal_errors(true);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="__wrap__">'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);

        $xp = new \DOMXPath($dom);
        foreach ($tags as $tag) {
            $nodes = $xp->query("//{$tag}");
            if (!$nodes) continue;
            foreach (iterator_to_array($nodes) as $node) {
                if ($node && $node->parentNode) $node->parentNode->removeChild($node);
            }
        }

        $wrap = $dom->getElementById('__wrap__');
        $clean = '';
        if ($wrap) {
            foreach ($wrap->childNodes as $child) {
                $clean .= $dom->saveHTML($child);
            }
        }

        libxml_clear_errors();
        return $clean;
    }

    protected function extractWikipediaMainHtml(string $fullHtml): string
    {
        libxml_use_internal_errors(true);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        // trik encoding supaya DOMDocument lebih aman parse UTF-8 [web:292]
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $fullHtml, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xp = new \DOMXPath($dom);

        $root = $xp->query("//*[@id='mw-content-text']//*[contains(@class,'mw-parser-output')][1]")->item(0);
        if (!$root) return '';

        // buang noise dalam root
        $remove = $xp->query(
            ".//*[@id='toc']
            | .//table[contains(@class,'infobox') or contains(@class,'sidebar') or contains(@class,'navbox') or contains(@class,'vertical-navbox')]
            | .//sup[contains(@class,'reference') or starts-with(@id,'cite_ref')]
            | .//ol[contains(@class,'references')]
            | .//div[@class='mw-references-wrap']
            | .//script | .//style | .//noscript",
            $root
        );

        foreach (iterator_to_array($remove ?? []) as $n) {
            if ($n && $n->parentNode) $n->parentNode->removeChild($n);
        }

        // ambil hanya node “artikel”
        $out = '<div>';
        $nodes = $xp->query(".//h2 | .//h3 | .//p[not(contains(@class,'mw-empty-elt'))] | .//ul | .//ol", $root);

        foreach (iterator_to_array($nodes ?? []) as $n) {
            $chunk = $dom->saveHTML($n);
            if (strlen(trim(strip_tags($chunk))) < 5) continue;
            $out .= $chunk;
        }
        $out .= '</div>';

        libxml_clear_errors();
        return $out;
    }

    protected function isPdfResponse($response, string $url): bool
    {
        $ct = strtolower((string) $response->header('Content-Type')); // e.g. application/pdf
        if (str_contains($ct, 'application/pdf')) return true;

        // fallback: dari ekstensi URL
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        return str_ends_with($path, '.pdf');
    }

    protected function pdfToTextFromUrl(string $url): string
    {
        $bin = Http::withHeaders($this->headers)->timeout(30)->get($url)->body();

        $tmp = tempnam(sys_get_temp_dir(), 'pdf_') . '.pdf';
        file_put_contents($tmp, $bin);

        try {
            return Pdf::getText($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    protected function pdfTextToMarkdown(string $text, string $url): string
    {
        $text = trim(preg_replace("/[ \t]+/", " ", $text));
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text));

        return "# PDF Document\n\nSource: {$url}\n\n---\n\n{$text}";
    }
}
