<?php

namespace App\Domain\Import\Correction;

/**
 * What a workbook CLAIMS to be, read from its defined names.
 *
 * Every field here is a claim and none of it is a permission. The file says it
 * is grid version 1 for instrument X of class Y, with item Z in column D — and
 * all of that is checked against the database before a single mark is written.
 * A workbook that names an instrument in another organization is refused, not
 * because the claim was malformed but because claims do not carry authority
 * (§6).
 *
 * Reading stops at the first structural problem. A grid whose version this
 * build does not know, or whose columns repeat an item, is not a grid to be
 * interpreted carefully — it is one to be refused with a sentence, because
 * interpreting it is how a mark reaches the wrong item (§14).
 */
final readonly class LapisGridDeclaration
{
    /**
     * @param  array<string, string>  $itemsByColumn  column letter => item ULID
     */
    private function __construct(
        public string $version,
        public ?string $instrumentUlid,
        public ?string $classUlid,
        public array $itemsByColumn,
        public ?string $refusal = null,
    ) {}

    /**
     * Reads a declaration, or returns null when the workbook is simply not one
     * of ours — which is not an error and sends the file down the ordinary
     * generic path (§15).
     *
     * @param  array<string, string>  $definedNames
     */
    public static function from(array $definedNames): ?self
    {
        $marker = $definedNames[LapisGridContract::NAME_MARKER] ?? null;

        if ($marker !== LapisGridContract::MARKER) {
            return null;
        }

        $version = $definedNames[LapisGridContract::NAME_VERSION] ?? '';

        if ($version !== LapisGridContract::VERSION) {
            return self::refused(
                $version,
                __('Esta grelha Lapispro foi criada por outra versão da aplicação e não pode ser importada automaticamente.'),
            );
        }

        $itemsByColumn = [];

        foreach ($definedNames as $name => $value) {
            $column = LapisGridContract::columnOf($name);

            if ($column !== null) {
                $itemsByColumn[$column] = $value;
            }
        }

        if ($itemsByColumn === []) {
            return self::refused($version, __('Esta grelha Lapispro não indica nenhuma pergunta e já não pode ser importada automaticamente.'));
        }

        // One item in two columns would mean two marks for one question, and
        // whichever landed last would win in silence.
        if (count(array_unique($itemsByColumn)) !== count($itemsByColumn)) {
            return self::refused($version, __('Esta grelha Lapispro tem colunas repetidas e já não pode ser importada automaticamente.'));
        }

        ksort($itemsByColumn);

        return new self(
            version: $version,
            instrumentUlid: $definedNames[LapisGridContract::NAME_INSTRUMENT] ?? null,
            classUlid: $definedNames[LapisGridContract::NAME_CLASS] ?? null,
            itemsByColumn: $itemsByColumn,
        );
    }

    protected static function refused(string $version, string $reason): self
    {
        return new self(version: $version, instrumentUlid: null, classUlid: null, itemsByColumn: [], refusal: $reason);
    }

    public function isUsable(): bool
    {
        return $this->refusal === null
            && $this->instrumentUlid !== null
            && $this->classUlid !== null
            && $this->itemsByColumn !== [];
    }

    /**
     * The refusal to show, when there is one. Always says what the teacher can
     * do next rather than only what went wrong.
     */
    public function reason(): string
    {
        return $this->refusal
            ?? __('Esta grelha Lapispro foi alterada na sua estrutura e já não pode ser importada automaticamente.');
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return array_keys($this->itemsByColumn);
    }

    /**
     * Facts about the file, for the provenance column. Identity of the
     * instrument only — never a student, never a name (§17).
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'lapis_grid' => true,
            'version' => $this->version,
            'instrument' => $this->instrumentUlid,
            'items' => count($this->itemsByColumn),
        ];
    }
}
