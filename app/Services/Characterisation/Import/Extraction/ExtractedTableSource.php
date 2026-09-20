<?php

namespace App\Services\Characterisation\Import\Extraction;

/**
 * Where an ExtractedTable came from.
 *
 * Kept even after normalisation into a TableGrid because the preview and the
 * audit trail both want to say what was imported — «colado de uma folha
 * Excel», «ficheiro Word» — and that phrase is cheaper to carry through than
 * to reconstruct later from a filename that may not even exist (a paste has
 * none).
 */
enum ExtractedTableSource: string
{
    case PastedHtml = 'pasted_html';
    case PastedTsv = 'pasted_tsv';
    case PastedImage = 'pasted_image';
    case Docx = 'docx';
    case Xlsx = 'xlsx';
    case Csv = 'csv';
    case ImageUpload = 'image_upload';

    // §39: what the structural review step (CharacterisationImportDialog's
    // "Rever tabela reconhecida") resubmits after the teacher corrects a
    // .docx or pasted-HTML table — carried as its OWN value, deliberately
    // distinct from ::Docx/::PastedHtml, so CharacterisationImportController
    // ::parseExtractedTablePayload can accept a correction WITHOUT reopening
    // the door that test guards: client JSON claiming to be a genuine ::Docx/
    // ::PastedHtml extraction is still refused (see
    // CharacterisationImportExtractedTableTest::an_invalid_source_type_is_refused).
    // A correction is real, teacher-reviewed text, but it did not come from
    // this server re-reading the original file, so it never claims to.
    case CorrectedDocx = 'corrected_docx';
    case CorrectedPastedHtml = 'corrected_pasted_html';

    public function label(): string
    {
        return match ($this) {
            self::PastedHtml => __('Tabela colada'),
            self::PastedTsv => __('Texto colado'),
            self::PastedImage => __('Imagem colada'),
            self::Docx => __('Documento Word'),
            self::Xlsx => __('Folha de cálculo Excel'),
            self::Csv => __('Ficheiro CSV'),
            self::ImageUpload => __('Imagem carregada'),
            self::CorrectedDocx => __('Documento Word (corrigido)'),
            self::CorrectedPastedHtml => __('Tabela colada (corrigida)'),
        };
    }
}
