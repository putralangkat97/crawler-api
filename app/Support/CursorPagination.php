<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class CursorPagination
{
    public static function apply(Builder $query, ?string $cursor, int $limit): array
    {
        if ($cursor) {
            $decoded = json_decode(base64_decode($cursor, true), true) ?? [];
            if (isset($decoded['created_at'], $decoded['id'])) {
                $query->where(function ($q) use ($decoded) {
                    $q->where('created_at', '>', $decoded['created_at'])
                        ->orWhere(function ($q) use ($decoded) {
                            $q->where('created_at', '=', $decoded['created_at'])
                                ->where('id', '>', $decoded['id']);
                        });
                });
            }
        }

        $items = $query->orderBy('created_at')->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $items->count() > $limit;
        $items = $items->take($limit);

        $nextCursor = null;
        if ($hasMore && $items->last()) {
            $nextCursor = base64_encode(json_encode([
                'created_at' => $items->last()->created_at?->toISOString(),
                'id' => $items->last()->id,
            ]));
        }

        return [$items, $nextCursor];
    }
}
