{{--
    A Análise da Avaliação (Pro), printed.

    WRITTEN FOR DOMPDF, WHICH IS NOT A BROWSER: tables and floats, no flexbox, no grid, no custom
    properties. It draws the SAME structure the on-screen preview draws (BuildAnalysisDocument) and reads
    nothing else — the structure carries finished text, so this view formats, rounds and rewords nothing:
    what is on the paper is what is on the screen, by construction.

    BLOCKS, NOT A LAYOUT. A section is a list of heading / paragraph / keyvalues / list / table blocks;
    adding a fact to the document is adding it in the builder, once, and both renderers pick it up.

    THE TIMBRE IS THE SHARED ONE (letterhead and footer partials). The «Página N / T» label is drawn by
    DompdfPrinter.

    THE HEADER ROW REPEATS and `page-break-inside: avoid` keeps a row in one piece, because a table of a
    whole class crosses pages.
--}}
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <title>{{ $document['title'] }}</title>
    <style>
        @include('documents.partials.letterhead-styles')

        h1 { font-family: DejaVu Serif, serif; font-size: 17pt; font-weight: normal; line-height: 1.25; margin: 0 0 1.5mm 0; }
        .subtitle { font-size: 9pt; color: #555; margin: 0 0 1mm 0; }
        .issued { font-size: 8pt; color: #666; margin: 0 0 4mm 0; }

        table.identification { border-collapse: collapse; margin: 0 0 4mm 0; font-size: 8.5pt; }
        table.identification td { padding: 0.6mm 4mm 0.6mm 0; vertical-align: top; }
        table.identification td.label { color: #555; }

        div.warnings { border: 0.5pt solid #b45309; padding: 2mm 3mm; margin: 0 0 5mm 0; font-size: 8pt; }
        div.warnings .title { font-weight: bold; margin: 0 0 1mm 0; }
        div.warnings ul { margin: 0; padding-left: 3.5mm; }

        h2 { font-size: 12pt; margin: 6mm 0 2mm 0; padding-bottom: 1mm; border-bottom: 0.6pt solid #999; page-break-after: avoid; }
        h3 { font-size: 10pt; margin: 4mm 0 1.5mm 0; page-break-after: avoid; }

        p { margin: 0 0 2mm 0; font-size: 9pt; }
        p.muted { color: #666; font-size: 8pt; }
        p.caution { font-size: 8.5pt; font-weight: bold; }

        table.kv { border-collapse: collapse; margin: 0 0 3mm 0; font-size: 9pt; width: 100%; }
        table.kv td { padding: 0.8mm 3mm 0.8mm 0; vertical-align: top; border-bottom: 0.3pt solid #eee; }
        table.kv td.label { width: 34%; font-weight: bold; }

        div.list { margin: 0 0 3mm 0; font-size: 8.5pt; }
        div.list .list-title { font-weight: bold; margin: 0 0 0.8mm 0; }
        div.list ul { margin: 0; padding-left: 3.5mm; }
        div.list li { margin-bottom: 0.8mm; }

        table.data { width: 100%; border-collapse: collapse; margin: 0 0 4mm 0; font-size: 8pt; }
        table.data thead { display: table-header-group; }
        table.data tr { page-break-inside: avoid; }
        table.data th { text-align: left; border-bottom: 0.6pt solid #999; padding: 1.2mm 2mm 1.2mm 0; font-weight: bold; }
        table.data td { border-bottom: 0.3pt solid #ddd; padding: 1.2mm 2mm 1.2mm 0; vertical-align: top; }
        .right { text-align: right; }

        p.empty { margin: 6mm 0; font-size: 10pt; }
        p.notice { font-size: 7.5pt; color: #666; margin: 6mm 0 0 0; }
    </style>
</head>
<body>

@include('documents.partials.letterhead', ['identity' => $document['identity']])

<h1>{{ $document['title'] }}</h1>
<p class="subtitle">{{ $document['subtitle'] }}</p>
<p class="issued">{{ $document['issued_label'] }}</p>

<table class="identification">
    @foreach ($document['identification'] as $row)
        <tr><td class="label">{{ $row['label'] }}</td><td>{{ $row['value'] }}</td></tr>
    @endforeach
</table>

@if ($document['warnings'] !== [])
    <div class="warnings">
        <p class="title">{{ __('Avisos: cobertura, comparabilidade e dados em falta') }}</p>
        <ul>
            @foreach ($document['warnings'] as $warning)
                <li>{{ $warning }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if ($document['is_empty'])
    <p class="empty">{{ __('Nenhuma secção selecionada.') }}</p>
@endif

@foreach ($document['sections'] as $section)
    <h2>{{ $section['title'] }}</h2>

    @foreach ($section['blocks'] as $block)
        @switch ($block['type'])
            @case ('heading')
                <h3>{{ $block['text'] }}</h3>
                @break

            @case ('paragraph')
                <p class="{{ $block['tone'] === 'plain' ? '' : $block['tone'] }}">{{ $block['text'] }}</p>
                @break

            @case ('keyvalues')
                <table class="kv">
                    @foreach ($block['items'] as $item)
                        <tr><td class="label">{{ $item['label'] }}</td><td>{{ $item['value'] }}</td></tr>
                    @endforeach
                </table>
                @break

            @case ('list')
                <div class="list">
                    @if ($block['title'] !== null)
                        <div class="list-title">{{ $block['title'] }}</div>
                    @endif
                    <ul>
                        @foreach ($block['items'] as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </div>
                @break

            @case ('table')
                <table class="data">
                    <thead>
                        <tr>
                            @foreach ($block['columns'] as $column)
                                <th class="{{ $column['align'] === 'right' ? 'right' : '' }}">{{ $column['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($block['rows'] as $row)
                            <tr>
                                @foreach ($row as $index => $cell)
                                    <td class="{{ $block['columns'][$index]['align'] === 'right' ? 'right' : '' }}">{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @break
        @endswitch
    @endforeach
@endforeach

<p class="notice">{{ $document['notice'] }}</p>

</body>
</html>
