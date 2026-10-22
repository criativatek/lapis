{{--
    The grid of a class — the Pauta de Avaliação — printed.

    WRITTEN FOR DOMPDF, WHICH IS NOT A BROWSER: tables, no flexbox, no grid, no
    custom properties. It renders the SAME structure the on-screen preview draws
    (EvaluationSheetGrid, plus the letterhead and the day of issue) and reads
    nothing else — which is why it cannot print a field the structure does not
    carry, and why a figure on paper is the one on screen. Every text — a cell, a
    mark, a header, a note — arrives composed; this file only places it.

    THE COLUMNS WERE ALREADY CUT INTO BLOCKS by EvaluationSheetGrid, because dompdf
    does not paginate horizontally: a column past the right margin is not carried
    to the next page, it is lost. Each block is a table of its own, with «Nº» and
    «Nome» repeated, and every block after the first starts a new page. Each column
    is drawn with the width the planner used (EvaluationSheetGrid::columnWidth), so
    a block that fits in the plan fits on the sheet.

    THE HEADINGS REPEAT. A class of thirty crosses pages, and a page of levels with
    no domain names above them is unreadable: the whole `thead` (group line and
    column names) repeats, and `page-break-inside: avoid` keeps a student's row in
    one piece.

    CALCULATED, PROPOSED, DECIDED — IN WORDS. Paper has no italics: the percentage
    is the «Resultado», the proposal is the column «Proposta do Lapispro», and the
    teacher's decision is the «Nível atribuído» and, per domain, the appreciation
    marked «prof.» with the proposal it replaced underneath. The state of the
    classification is a word.

    A PROVISIONAL SHEET SAYS SO ON EVERY PAGE. The stamp is a fixed block, painted
    once per page like the letterhead, because a copy with levels still to be
    assigned must not be taken for the final one from page 2 on.

    THE CHARTS ARE TABLES. dompdf has no flexbox, no gradients and cannot run Chart.js:
    a bar is a table cell whose width is the percentage the server measured, inside a
    framed track. Every label, figure and width arrives composed
    (EvaluationSheetGridCharts) and is drawn as given, so the PDF cannot disagree with
    the preview. A bar with no width is an empty track — never a bar at zero.

    THE TIMBRE IS THE SHARED ONE (`$layout`: Standard for a portrait sheet, Compact
    for a landscape one). «Página N / T» is drawn by DompdfPrinter.
--}}
@php
    $widthOf = fn (array $column): string => number_format(\App\Services\Reporting\ClassDocuments\EvaluationSheetGrid::columnWidth($column['kind']), 1, '.', '').'mm';
    $numberWidth = number_format(\App\Services\Reporting\ClassDocuments\InstrumentGridLayout::NUMBER_WIDTH, 1, '.', '').'mm';
    // The name column keeps the width the plan reserved for it: left to dompdf's
    // auto layout it shrinks as columns are added and breaks names over two lines.
    $nameWidth = number_format(\App\Services\Reporting\ClassDocuments\InstrumentGridLayout::NAME_WIDTH, 1, '.', '').'mm';
@endphp
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <title>{{ $document['title'] }}</title>
    <style>
        @include('documents.partials.letterhead-styles')

        h1 {
            font-family: DejaVu Serif, serif;
            font-size: 16pt;
            font-weight: normal;
            line-height: 1.25;
            margin: 0 0 1.5mm 0;
        }

        .kind {
            font-size: 7.5pt;
            color: #666;
            text-transform: uppercase;
            margin: 0 0 0.5mm 0;
        }

        .subtitle {
            font-size: 9pt;
            color: #555;
            margin: 0 0 1mm 0;
        }

        .issued {
            font-size: 8pt;
            color: #666;
            margin: 0 0 2mm 0;
        }

        .state {
            font-size: 9pt;
            font-weight: bold;
            margin: 0 0 5mm 0;
        }

        /* On every page: fixed, like the letterhead. */
        #provisional {
            position: fixed;
            top: {{ $layout->letterheadTop() }};
            left: 0;
            right: 0;
            text-align: right;
            font-size: 8.5pt;
            font-weight: bold;
            letter-spacing: 0.4pt;
        }

        #provisional span {
            border: 0.8pt solid #000;
            padding: 0.6mm 2mm;
        }

        h2 {
            font-size: 10pt;
            margin: 0 0 2mm 0;
            page-break-after: avoid;
        }

        table.grid {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 5mm 0;
            font-size: 7.5pt;
            line-height: 1.3;
        }

        /* The whole heading repeats when a long grid crosses a page. */
        table.grid thead {
            display: table-header-group;
        }

        table.grid tr {
            page-break-inside: avoid;
        }

        table.grid th {
            border-bottom: 0.6pt solid #999;
            padding: 1mm 0.8mm;
            font-weight: bold;
            text-align: center;
        }

        table.grid th.left, table.grid td.left {
            text-align: left;
        }

        table.grid tr.groups th {
            border-bottom: 0.4pt solid #bbb;
            color: #444;
        }

        table.grid td {
            border-bottom: 0.3pt solid #ddd;
            padding: 1mm 0.8mm;
            vertical-align: top;
            text-align: center;
        }

        table.grid td.name {
            text-align: left;
            font-weight: bold;
        }

        .detail {
            font-size: 6.5pt;
            font-weight: normal;
            color: #666;
        }

        .mark {
            font-size: 6.5pt;
            font-weight: bold;
        }

        .strong {
            font-weight: bold;
        }

        p.empty {
            margin: 6mm 0;
            font-size: 10pt;
        }

        .notes {
            font-size: 7.5pt;
            color: #666;
            margin: 0 0 1.5mm 0;
        }

        div.chart {
            margin: 0 0 5mm 0;
            page-break-inside: avoid;
        }

        table.chart {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 1.5mm 0;
            font-size: 7.5pt;
            line-height: 1.3;
        }

        table.chart tr {
            page-break-inside: avoid;
        }

        td.chart-label {
            width: 44mm;
            padding: 0.9mm 2mm 0.9mm 0;
            vertical-align: middle;
            font-weight: bold;
        }

        td.chart-track {
            padding: 0.9mm 0;
            vertical-align: middle;
        }

        table.track {
            width: 100%;
            border-collapse: collapse;
            border: 0.4pt solid #999;
        }

        td.fill {
            height: 3.2mm;
            background-color: #555555;
            font-size: 1pt;
            line-height: 1;
        }

        /* A level the scale calls negative: a lighter fill with a dark edge, readable in black and white. */
        td.fill.negative {
            background-color: #bbbbbb;
            border-right: 0.9pt solid #222222;
        }

        td.rest {
            height: 3.2mm;
            font-size: 1pt;
            line-height: 1;
        }

        td.chart-value {
            width: 46mm;
            padding: 0.9mm 0 0.9mm 2mm;
            vertical-align: middle;
        }
    </style>
</head>
<body>

@include('documents.partials.letterhead', ['identity' => $document['identity']])

@if ($document['state']['is_provisional'])
    <div id="provisional"><span>{{ $document['state']['label'] }}</span></div>
@endif

<p class="kind">{{ $document['kind_label'] }}</p>
<h1>{{ $document['title'] }}</h1>
<p class="subtitle">{{ $document['subtitle'] }}</p>
<p class="issued">
    {{ $document['meta']['academic_year'] }} · {{ $document['meta']['period_kind'] }} — {{ $document['meta']['period'] }} · {{ $document['meta']['scope'] }}@if ($document['meta']['reference_date']) · {{ __('Data de referência') }} {{ $document['meta']['reference_date'] }}@endif
    · {{ $document['issued_label'] }}
</p>
<p class="state">
    {{ $document['state']['label'] }} · {{ $document['source']['label'] }}@if ($document['source']['is_historical'] && $document['source']['author']) · {{ __('por :author', ['author' => $document['source']['author']]) }}@endif
</p>

@if ($document['is_empty'])
    <p class="empty">{{ $document['empty_message'] }}</p>
@else
    @foreach ($document['blocks'] as $index => $block)
        <div @if ($index > 0) style="page-break-before: always;" @endif>
            @if ($block['caption'])
                <h2>{{ $block['caption'] }}</h2>
            @endif

            <table class="grid">
                <thead>
                    <tr class="groups">
                        <th colspan="2"></th>
                        @foreach ($block['group_spans'] as $span)
                            <th colspan="{{ $span['span'] }}">{{ $span['label'] }}</th>
                        @endforeach
                    </tr>
                    <tr>
                        <th class="left" style="width: {{ $numberWidth }};">{{ __('Nº') }}</th>
                        <th class="left" style="width: {{ $nameWidth }};">{{ __('Nome') }}</th>
                        @foreach ($block['columns'] as $column)
                            <th style="width: {{ $widthOf($column) }};">{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($block['rows'] as $row)
                        <tr>
                            <td class="left">{{ $row['number'] }}</td>
                            <td class="name">{{ $row['name'] }}</td>
                            @foreach ($row['cells'] as $cell)
                                <td>{{ $cell['text'] }}@if ($cell['mark']) <span class="mark">{{ $cell['mark'] }}</span>@endif @if ($cell['detail'])<br><span class="detail">{{ $cell['detail'] }}</span>@endif</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
@endif

@if ($document['charts'])
    @foreach (array_filter([$document['charts']['proposals'], $document['charts']['assigned'], $document['charts']['means']]) as $chart)
        <div class="chart">
            <h2>{{ $chart['title'] }}</h2>
            <p class="notes">{{ $chart['basis'] }}@if (isset($chart['n_label'])) · {{ $chart['n_label'] }}@endif</p>

            @if (($chart['available'] ?? true) === false)
                <p class="notes">{{ $chart['unavailable_message'] }}</p>
            @elseif ($chart['bars'] === [])
                <p class="notes">{{ $chart['empty_message'] }}</p>
            @else
                <table class="chart">
                    @foreach ($chart['bars'] as $bar)
                        <tr>
                            <td class="chart-label">{{ $bar['label'] }}</td>
                            <td class="chart-track">
                                <table class="track">
                                    <tr>
                                        @if ($bar['width'] !== null && (float) $bar['width'] > 0)
                                            <td class="fill{{ $bar['negative'] ? ' negative' : '' }}" style="width: {{ $bar['width'] }}%;">&nbsp;</td>
                                        @endif
                                        <td class="rest">&nbsp;</td>
                                    </tr>
                                </table>
                            </td>
                            <td class="chart-value">{{ $bar['value_text'] }}@if ($bar['detail'])<br><span class="detail">{{ $bar['detail'] }}</span>@endif</td>
                        </tr>
                    @endforeach
                </table>
            @endif

            @if (isset($chart['axis']))
                <p class="notes">{{ $chart['axis'] }}</p>
            @endif
            @if (! empty($chart['missing_note']))
                <p class="notes">{{ $chart['missing_note'] }}</p>
            @endif
            @if (! empty($chart['unplaced_note']))
                <p class="notes">{{ $chart['unplaced_note'] }}</p>
            @endif
            @if ($chart['caution'])
                <p class="notes strong">{{ $chart['caution'] }}</p>
            @endif
        </div>
    @endforeach
@endif

@foreach ($document['notes'] as $note)
    <p class="notes">{{ $note }}</p>
@endforeach
@foreach ($document['warnings'] as $warning)
    <p class="notes"><span class="strong">{{ __('Aviso registado:') }}</span> {{ $warning }}</p>
@endforeach
<p class="notes">{{ $document['notice'] }}</p>

</body>
</html>
