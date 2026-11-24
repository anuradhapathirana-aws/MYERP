<?php

declare(strict_types=1);

namespace App\Support\Journal;

use App\Contracts\DocumentLocationSource;

/** No module owns sales documents: there is nothing to look a location up in. */
final class NullDocumentLocationSource implements DocumentLocationSource
{
    public function sourceTypes(): array
    {
        return [];
    }

    public function locationsFor(string $sourceType, array $ids): array
    {
        return [];
    }
}
