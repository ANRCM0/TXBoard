<?php

namespace App\Domains\Content\Contracts;

use InvalidArgumentException;

final readonly class ContentListQuery
{
    public function __construct(public string $kind, public int $page = 1, public int $perPage = 20,
        public ?string $title = null, public ?string $category = null, public ?string $language = null)
    {
        if (!in_array($kind, ['notices', 'knowledge'], true) || $page < 1 || $page > 100000
            || $perPage < 1 || $perPage > 20 || $title !== null && mb_strlen($title) > 255
            || $category !== null && mb_strlen($category) > 255 || $language !== null && strlen($language) > 32) {
            throw new InvalidArgumentException('CONTENT_QUERY_INVALID');
        }
    }
}
