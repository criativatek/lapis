<?php

namespace App\Http\Controllers;

use App\Domain\Import\PhotoMatch;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Services\Import\PhotoFileParser;
use App\Services\Import\RosterFileParseException;
use App\Services\Import\RosterImportPreviewBuilder;
use App\Support\Help\HelpArticle;
use App\Support\Help\HelpCenter;
use App\Support\Import\RosterImportTempStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Adicionar ou corrigir fotos» — the photo half of an import, on a class that
 * already exists.
 *
 * IT USED TO APPLY IMMEDIATELY. Upload the Word file, and every photo it could
 * match by name was written to a student's record on the spot; the teacher was
 * told «N foto(s) associada(s)» and nothing else. When N came back 0 — which is
 * what a file exported without «colocar o nome ao lado da foto» always produced,
 * because the parser dropped captionless images entirely — there was no list of
 * who was missed, no photo to look at, and no way to assign one by hand. The
 * only apparent way out was to delete the students and import the class again,
 * which throws away every result, record and intervention they had.
 *
 * So this no longer writes anything. It stages the parsed photos under a token
 * and renders the SAME roster-imports/Preview page the roster flow uses, with
 * one row per enrolment already on the roll. The teacher sees who was matched,
 * who was not, and every photo in the file — including the ones with no name,
 * which can be assigned by hand. RosterImportController::confirm() applies it,
 * and it is the only thing here that touches the database (§6).
 *
 * Reusing that page rather than adding a second wizard is the point: one
 * preview, one confirm, one discard, one temp-folder lifecycle, one prune
 * command. A parallel flow would be a second place for the photo of a child to
 * be left lying on disk.
 */
class ClassPhotoImportController extends Controller
{
    public function __construct(
        protected PhotoFileParser $photoParser,
        protected RosterImportPreviewBuilder $previewBuilder,
        protected RosterImportTempStorage $tempStorage,
        protected HelpCenter $helpCenter,
    ) {}

    public function store(Request $request, SchoolClass $class): Response|RedirectResponse
    {
        Gate::authorize('update', $class);

        $data = $request->validate([
            'photos' => ['required', 'file', 'mimes:doc,docx'],
        ]);

        try {
            $photoMatches = $this->photoParser->parse($data['photos']->getRealPath());
        } catch (RosterFileParseException $exception) {
            return back()->withErrors(['photos' => $exception->getMessage()]);
        }

        if ($photoMatches === []) {
            return back()->withErrors([
                'photos' => 'Não foi possível encontrar nenhuma fotografia neste ficheiro.',
            ]);
        }

        // The class as it stands, in the order the teacher reads it. EVERY
        // enrolment, not only the active ones: a student who transferred out
        // mid-year still has a record, and correcting the photo on it is not
        // the same thing as putting them back on the roll (§7).
        $enrollments = $class->enrollments()
            ->with('student.identity')
            ->get()
            ->sortBy([
                fn (Enrollment $enrollment): int => $enrollment->class_number ?? PHP_INT_MAX,
                fn (Enrollment $enrollment): string => $this->nameOf($enrollment),
            ])
            ->values();

        $token = $this->tempStorage->newToken($class->id);

        foreach ($photoMatches as $index => $photo) {
            $this->tempStorage->storePhoto($token, $index, $photo->imageBytes, $photo->extension);
        }

        $rows = $this->previewBuilder->matchPhotosToRows(
            array_values($enrollments->map(fn (Enrollment $enrollment): array => $this->rowFor($enrollment))->all()),
            $photoMatches,
        );

        // Only the rows that actually got a photo start ticked. A re-import is
        // a correction, not an occasion to rewrite thirty records that were
        // already right — and every row stays there, unticked, for the teacher
        // to assign a photo to by hand.
        $rows = array_map(
            fn (array $row): array => [...$row, 'include' => $row['photo_index'] !== null],
            $rows,
        );

        return Inertia::render('roster-imports/Preview', [
            'schoolClassUlid' => $class->ulid,
            'token' => $token,
            'rows' => $rows,
            'photos' => $this->photoPool($photoMatches),
            'flow' => 'photos',
            'helpArticles' => array_values($this->helpCenter->forContext('classes.roster-imports.store')
                ->map(fn (HelpArticle $article): array => $article->toArray())
                ->all()),
        ]);
    }

    /**
     * One preview row for a student already on the roll.
     *
     * The shape is exactly RosterImportPreviewBuilder::build()'s, because the
     * same Vue page and the same confirm() rules read both. What differs is
     * where the values come from: the record, not a file. `enrollment_id` is
     * always present and `action` is always «update», so confirm() can only
     * ever fill this student's record in — there is no path from this flow to
     * enrolling anybody.
     *
     * The name travels so it can be shown and, if it is wrong, corrected —
     * that is the other half of §5. A student who never got an identity
     * travels with an empty one rather than a placeholder: «(sem identidade)»
     * is a thing a screen says, never a name to write into a record.
     *
     * @return array<string, mixed>
     */
    protected function rowFor(Enrollment $enrollment): array
    {
        $identity = $enrollment->student?->identity;
        $name = $identity === null ? '' : $identity->display_name;

        return [
            'name' => $name,
            'class_number' => $enrollment->class_number,
            'birth_date' => null,
            'situation_code' => null,
            'situation_recognized' => true,
            'situation_label' => null,
            'current_state' => $enrollment->status_reason?->label() ?? $enrollment->status->label(),
            'state_changes' => false,
            'process_number' => null,
            'note' => null,
            'photo_index' => null,
            'photo_extension' => null,
            'duplicate_in_file' => false,
            'duplicate_target' => false,
            'already_enrolled' => true,
            'enrollment_id' => (int) $enrollment->getKey(),
            'matched_by' => null,
            'ambiguous' => false,
            'candidates' => [],
            'current_name' => $name,
            'name_changes' => false,
            'has_photo_today' => $identity?->photo_path !== null,
            'action' => RosterImportPreviewBuilder::ACTION_UPDATE,
            'include' => false,
        ];
    }

    /**
     * The name on record, or an empty string for a student who never got an
     * identity. Never «(sem identidade)»: that is a thing a screen says about
     * a record, not a name to sort by or to write into one.
     */
    protected function nameOf(Enrollment $enrollment): string
    {
        $identity = $enrollment->student?->identity;

        return $identity === null ? '' : $identity->display_name;
    }

    /**
     * @param  list<PhotoMatch>  $photoMatches
     * @return list<array{index: int, extension: string, named: bool}>
     */
    protected function photoPool(array $photoMatches): array
    {
        $photos = [];

        foreach ($photoMatches as $index => $photo) {
            $photos[] = [
                'index' => $index,
                'extension' => $photo->extension,
                'named' => trim($photo->name) !== '',
            ];
        }

        return $photos;
    }
}
