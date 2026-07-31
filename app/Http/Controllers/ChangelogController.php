<?php

namespace App\Http\Controllers;

use App\Support\Changelog\ChangelogParser;
use Inertia\Inertia;
use Inertia\Response;

class ChangelogController extends Controller
{
    public function __construct(protected ChangelogParser $parser) {}

    public function index(): Response
    {
        $entries = $this->parser->parse(base_path('CHANGELOG.md'));

        return Inertia::render('changelog/Index', [
            'entries' => array_map(fn ($entry) => [
                'version' => $entry->version,
                'date' => $entry->date,
                'sections' => $entry->sections,
            ], $entries),
        ]);
    }
}
