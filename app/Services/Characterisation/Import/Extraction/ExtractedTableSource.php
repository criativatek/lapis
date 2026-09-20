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
        };
    }
}
