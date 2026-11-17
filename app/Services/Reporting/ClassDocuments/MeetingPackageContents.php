<?php

namespace App\Services\Reporting\ClassDocuments;

/**
 * What the «Pacote de reunião» of a class holds, as `MeetingPackage::compose()` made it: the files by
 * their name in the archive, and what is said about each document that is not in it.
 *
 * COUNTS AND KEYS, NEVER A NAME. `included`, `omitted` and the element counts are what the audit trail
 * records; the files are what is archived. Nothing here is kept: the object lives for the length of one
 * request.
 */
final readonly class MeetingPackageContents
{
    /**
     * @param  array<string, string>  $files  archive name => bytes, in the order they are archived
     * @param  list<string>  $included  document keys: `pauta`, `quadro-sintese`, `elementos`
     * @param  list<array{document: string, code: string, reason: string}>  $omitted  whole documents left out; `code` is for the trail, `reason` for the teacher
     * @param  int  $elementsIncluded  how many grids of an evaluation element are in the archive
     * @param  int  $elementsProvisional  of those, how many have a correction not yet concluded
     * @param  array<string, int>  $elementsOmitted  reason code => how many elements of the class were left out for it
     * @param  int  $periodsIncluded  how many periods of the year the package reaches back over (the one asked for included)
     * @param  int  $sheetsIncluded  how many Pautas are in the archive (one per period)
     * @param  int  $elementsOutsideAccumulated  of the elements included, how many the profile leaves out of the accumulated
     */
    public function __construct(
        public array $files,
        public array $included,
        public array $omitted,
        public int $students,
        public string $archiveName,
        public int $elementsIncluded = 0,
        public int $elementsProvisional = 0,
        public array $elementsOmitted = [],
        public int $periodsIncluded = 1,
        public int $sheetsIncluded = 1,
        public int $elementsOutsideAccumulated = 0,
    ) {}
}
