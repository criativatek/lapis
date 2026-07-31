<?php

namespace App\Support\Changelog;

class ChangelogEntry
{
    /**
     * @param  list<array{category: string, items: list<string>}>  $sections
     */
    public function __construct(
        public readonly string $version,
        public readonly string $date,
        public readonly array $sections,
    ) {}
}
