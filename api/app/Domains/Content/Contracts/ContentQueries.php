<?php

namespace App\Domains\Content\Contracts;

interface ContentQueries
{
    public function listing(int $actorId, ContentListQuery $query, bool $admin = false): ContentPage;
    public function find(int $actorId, string $kind, string $id, bool $admin = false): ?array;
    public function categories(int $actorId, ?string $language, bool $admin = false): array;
    public function audit(int $actorId, string $afterId, int $limit): ContentPage;
}
