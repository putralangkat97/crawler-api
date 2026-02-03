<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class PolitenessLimiter
{
    public function throttle(string $host, array $polite): void
    {
        $delayMs = (int) ($polite['per_host_delay_ms'] ?? 1200);
        $jitterRatio = (float) ($polite['jitter_ratio'] ?? 0.3);
        $minDelay = max(0, (int) ($delayMs * (1 - $jitterRatio)));
        $maxDelay = (int) ($delayMs * (1 + $jitterRatio));
        $targetDelay = random_int($minDelay, $maxDelay);

        $key = 'polite:'.$host.':last';
        $last = (int) Cache::get($key, 0);
        $now = (int) (microtime(true) * 1000);
        $wait = max(0, $targetDelay - ($now - $last));

        if ($wait > 0) {
            usleep($wait * 1000);
        }

        Cache::put($key, (int) (microtime(true) * 1000), 60);
    }
}
