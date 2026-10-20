<?php

namespace App\Http\Controllers;

use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Services\Reporting\ClassDocuments\InstrumentGrid;
use App\Services\Reporting\ClassDocuments\InstrumentGridOptions;
use App\Services\Reporting\ClassDocuments\InstrumentGridUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The printable grid of one instrument — the preview.
 *
 * A DOCUMENT MADE WHEN IT IS ASKED FOR, NOT A REPORT. Nothing is stored and
 * nothing is written: the page is built on the spot from the instrument as it
 * stands (InstrumentGrid), and the document it receives is that structure
 * UNTOUCHED, so what is on the screen is what the PDF will draw from the same
 * array. A teacher who corrects another cell and asks again gets the new grid.
 *
 * AUTHORIZED LIKE THE CLASS ITSELF, the same gate the correction grid and
 * Resultados use: whoever teaches the class (SchoolClassPolicy::view). A
 * colleague who does not gets a 403; an instrument of another organization does
 * not resolve at all, because the route binding runs through the tenant scope.
 * It is a GET, so it also works while the account is read-only.
 *
 * THE OPTIONS ARE PRESENTATION. They arrive in the query string and are read
 * against a whitelist (InstrumentGridOptions::fromQuery): a forged value is the
 * default, and none of them ever reaches a query that could widen what the
 * teacher may see.
 *
 * WHERE IT DOES NOT APPLY. An instrument still being prepared has no grid to
 * print — the editor is where it goes on, as the correction grid itself does. An
 * annulled one has no results to put on paper: back to its page, with the reason.
 */
class InstrumentGridDocumentController extends Controller
{
    public function __construct(protected InstrumentGrid $grid) {}

    public function show(Request $request, Instrument $instrument): Response|RedirectResponse
    {
        Gate::authorize('view', $instrument->schoolClass);

        if ($instrument->status === InstrumentStatus::Draft) {
            return to_route('instruments.edit', $instrument->ulid);
        }

        try {
            $document = $this->grid->build($instrument, InstrumentGridOptions::fromQuery($request->query()));
        } catch (InstrumentGridUnavailable $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return to_route('instruments.show', $instrument->ulid);
        }

        return Inertia::render('instruments/GridDocument', [
            // What the page needs to link back — no numeric id leaves here.
            'instrument' => [
                'ulid' => $instrument->ulid,
                'title' => $instrument->title,
                'class_ulid' => $instrument->schoolClass->ulid,
            ],
            'document' => $document,
        ]);
    }
}
