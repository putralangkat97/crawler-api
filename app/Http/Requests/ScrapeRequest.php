<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScrapeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'url' => [
                'required',
                function (string $attribute, $value, $fail): void {
                    if (is_array($value)) {
                        foreach ($value as $item) {
                            if (! filter_var($item, FILTER_VALIDATE_URL)) {
                                $fail('Each url must be a valid URL.');
                            }
                        }
                    } elseif (! filter_var($value, FILTER_VALIDATE_URL)) {
                        $fail('The url must be a valid URL.');
                    }
                },
            ],
            'request' => 'sometimes|string|in:http,chrome,smart',
            'return_format' => 'sometimes|string|in:markdown,commonmark,raw,text,xml,bytes,empty',
            'metadata' => 'sometimes|boolean',
            'session' => 'sometimes|boolean',
            'scroll' => 'sometimes|integer|min:0|max:30000',
            'wait_for' => 'sometimes|array',
            'timeout_ms' => 'sometimes|integer|min:1000|max:120000',
            'max_bytes' => 'sometimes|integer|min:1024|max:50000000',
        ];
    }
}
