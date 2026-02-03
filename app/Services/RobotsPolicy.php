<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RobotsPolicy
{
    public function isAllowed(string $url, string $userAgent = '*'): bool
    {
        $parts = parse_url($url);
        if (! $parts || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $robotsUrl = $parts['scheme'].'://'.$parts['host'].'/robots.txt';
        $cacheKey = 'robots:'.$parts['host'];

        $rules = Cache::remember($cacheKey, 21600, function () use ($robotsUrl) {
            $response = Http::timeout(5)->get($robotsUrl);

            /** @disregard P1013 */
            if (! $response->successful()) {
                return [];
            }

            /** @disregard P1013 */
            return $this->parseRobots($response->body());
        });

        return $this->isPathAllowed($url, $rules, $userAgent);
    }

    protected function parseRobots(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $body);
        $rules = [];
        $currentAgent = null;

        foreach ($lines as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if ($line === '') {
                continue;
            }

            if (str_starts_with(strtolower($line), 'user-agent:')) {
                $currentAgent = trim(substr($line, strlen('user-agent:')));
                $rules[$currentAgent] = $rules[$currentAgent] ?? ['allow' => [], 'disallow' => []];
            }

            if (str_starts_with(strtolower($line), 'disallow:') && $currentAgent !== null) {
                $rules[$currentAgent]['disallow'][] = trim(substr($line, strlen('disallow:')));
            }

            if (str_starts_with(strtolower($line), 'allow:') && $currentAgent !== null) {
                $rules[$currentAgent]['allow'][] = trim(substr($line, strlen('allow:')));
            }
        }

        return $rules;
    }

    protected function isPathAllowed(string $url, array $rules, string $userAgent): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        $uaRules = $rules[$userAgent] ?? $rules['*'] ?? ['allow' => [], 'disallow' => []];

        foreach ($uaRules['disallow'] as $rule) {
            if ($rule === '') {
                continue;
            }
            if (str_starts_with($path, $rule)) {
                return false;
            }
        }

        return true;
    }
}
