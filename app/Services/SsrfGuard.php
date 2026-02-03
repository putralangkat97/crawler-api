<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SsrfGuard
{
    public function validate(string $url): ?array
    {
        $parts = parse_url($url);
        if (! $parts || ! isset($parts['scheme'], $parts['host'])) {
            return ['code' => 'INVALID_URL', 'message' => 'Invalid URL'];
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return ['code' => 'SSRF_BLOCKED', 'message' => 'Only http/https schemes are allowed'];
        }

        $host = strtolower($parts['host']);
        $cacheKey = 'dns:'.$host;
        $ips = Cache::remember($cacheKey, 300, function () use ($host) {
            $records = dns_get_record($host, DNS_A + DNS_AAAA);
            $resolved = [];
            foreach ($records as $record) {
                if (! empty($record['ip'])) {
                    $resolved[] = $record['ip'];
                }
                if (! empty($record['ipv6'])) {
                    $resolved[] = $record['ipv6'];
                }
            }

            return array_unique($resolved);
        });

        if (empty($ips)) {
            return ['code' => 'SSRF_BLOCKED', 'message' => 'Unable to resolve host'];
        }

        foreach ($ips as $ip) {
            if ($this->isPrivateIp($ip)) {
                return ['code' => 'SSRF_BLOCKED', 'message' => 'Private or restricted IP blocked'];
            }
        }

        return null;
    }

    protected function isPrivateIp(string $ip): bool
    {
        if (Str::contains($ip, ':')) {
            return $this->isPrivateIpv6($ip);
        }

        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }

        $ranges = [
            ['0.0.0.0', '0.255.255.255'],
            ['10.0.0.0', '10.255.255.255'],
            ['127.0.0.0', '127.255.255.255'],
            ['169.254.0.0', '169.254.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['224.0.0.0', '239.255.255.255'],
            ['240.0.0.0', '255.255.255.255'],
        ];

        foreach ($ranges as [$start, $end]) {
            if ($long >= ip2long($start) && $long <= ip2long($end)) {
                return true;
            }
        }

        return false;
    }

    protected function isPrivateIpv6(string $ip): bool
    {
        return str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd') || str_starts_with($ip, 'fe80') || $ip === '::1';
    }
}
