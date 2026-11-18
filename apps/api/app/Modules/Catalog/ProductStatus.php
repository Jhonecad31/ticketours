<?php

namespace App\Modules\Catalog;

enum ProductStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Published = 'published';
    case Suspended = 'suspended';
    case Archived = 'archived';

    public function canMoveTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Review, self::Published, self::Archived],
            self::Review => [self::Draft, self::Published, self::Archived],
            self::Published => [self::Suspended],
            self::Suspended => [self::Review, self::Published, self::Archived],
            self::Archived => [],
        }, true);
    }
}
