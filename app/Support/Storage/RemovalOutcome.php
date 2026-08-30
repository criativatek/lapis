<?php

namespace App\Support\Storage;

/**
 * What actually happened when we asked for a private file to be deleted.
 *
 * A boolean was not enough, and `void` was actively dangerous. Flysystem's
 * local adapter starts its delete with `file_exists()` and returns early when
 * that is false — but `file_exists()` answers false both for "there is no such
 * file" and for "this directory will not let me look", and only the first is
 * proof. On 2026-08-30 that difference was the whole bug: the scheduler could
 * not traverse `storage/app/private/data-imports` (0700, owned by another
 * user), every delete reported success, and the rows were about to be marked
 * "o ficheiro carregado foi removido" over files that were still there.
 *
 * So the caller gets three answers, not two, and the middle one is the point:
 * `AlreadyAbsent` is only ever returned when absence could actually be
 * verified. Anything else — including "I could not tell" — is `Failed`.
 *
 * The rule every caller owes this enum: never clear a stored path unless
 * `pointerMayBeCleared()` says the file is genuinely gone.
 */
enum RemovalOutcome: string
{
    /** It was there, we deleted it, and it is no longer there. */
    case Removed = 'removed';

    /** It is verifiably not there — nothing to do, nothing lost. */
    case AlreadyAbsent = 'already_absent';

    /** We could not delete it, or could not prove it is gone. Assume it is still there. */
    case Failed = 'failed';

    /**
     * Whether the database may now forget where the file was.
     *
     * The pointer is the only record of whose data a private upload held.
     * Dropping it while the file survives leaves an unattributable file on
     * disk — a worse outcome than simply retrying the delete tomorrow.
     */
    public function pointerMayBeCleared(): bool
    {
        return $this !== self::Failed;
    }

    public function isFailure(): bool
    {
        return $this === self::Failed;
    }
}
