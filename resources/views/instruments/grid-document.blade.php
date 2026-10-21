{{--
    The grid of an instrument, printed.

    WRITTEN FOR DOMPDF, WHICH IS NOT A BROWSER: tables, no flexbox, no grid, no
    custom properties. It renders the SAME structure the on-screen preview draws
    (InstrumentGrid) and reads nothing else — which is why it cannot print a field
    the structure does not carry, and why a figure on paper is the one on screen.
    Every text — a cell, a header, a note, a number — arrives composed; this file
    only places it.

    THE COLUMNS WERE ALREADY CUT INTO BLOCKS by InstrumentGrid, because dompdf does
    not paginate horizontally: a column past the right margin is not carried to the
    next page, it is lost. Each block is a table of its own, with «Nº» and «Nome»
    repeated, and every block after the first starts a new page.

    THE HEADINGS REPEAT. A grid of a whole class crosses pages, and a page of marks
    with no question codes above them is unreadable: the whole `thead` (group line,
    codes, quotas) repeats, and `page-break-inside: avoid` keeps a student's row in
    one piece.

    THE CHARTS ARE TABLES. dompdf has no flexbox, no gradients and cannot run Chart.js:
    a bar is a table cell whose width is the percentage the server measured, inside a
    framed track. Every label, figure and width arrives composed (InstrumentGridCharts)
    and is drawn as given, so the PDF cannot disagree with the preview. A bar with no
    width is an empty track — never a bar at zero.

    A PROVISIONAL GRID SAYS SO ON EVERY PAGE. The stamp is a fixed block, painted
    once per page like the letterhead, because a copy of a correction still under
    way must not be taken for the final one from page 2 on.

    THE TIMBRE IS THE SHARED ONE (`$layout`: Standard for a portrait sheet, Compact
    for a landscape one). «Página N / T» is drawn by DompdfPrinter.
--}}
@php
    $widthOf = fn (array $column): string => number_format(match ($column['kind']) {
        'item' => \App\Services\Reporting\ClassDocuments\InstrumentGridLayout::ITEM_WIDTH,
        'raw' => \App\Services\Reporting\ClassDocuments\InstrumentGridLayout::RAW_WIDTH,
        'result' => \App\Services\Reporting\ClassDocuments\InstrumentGridLayout::RESULT_WIDTH,
        'level' => \App\Services\Reporting\ClassDocuments\InstrumentGridLayout::LEVEL_WIDTH,
        default => \App\Services\Reporting\ClassDocuments\InstrumentGridLayout::DOMAIN_WIDTH,
    }, 1, '.', '').'mm';
    $numberWidth = number_format(\App\Services\Reporting\ClassDocuments\InstrumentGridLayout::NUMBER_WIDTH, 1, '.', '').'mm';
    // The name column keeps the width the plan reserved for it: left to dompdf's
    // auto layout it shrinks as columns are added and breaks names over two lines.
    $nameWidth = number_format(\App\Services\Reporting\ClassDocuments\InstrumentGridLayout::NAME_WIDTH, 1, '.', '').'mm';
    $header = fn (array $column): string => $column['kind'] === 'item' ? $column['code'] : $column['label'];
    $hasGroups = ($document['legend']['questions'] ?? null) !== null
        && collect($document['legend']['questions'])->contains(fn (array $question): bool => ($question['group'] ?? '') !== '');
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

        table.grid th.left, table.grid td.left, table.grid tr.quota th.left {
            text-align: left;
        }

        table.grid tr.groups th {
            border-bottom: 0.4pt solid #bbb;
            color: #444;
        }

        table.grid tr.quota th, table.grid tr.quota td {
            border-bottom: 0.6pt solid #999;
            font-weight: normal;
            color: #555;
            padding: 0.6mm 0.8mm;
            text-align: center;
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

        .strong {
            font-weight: bold;
        }

        table.legend {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 5mm 0;
            font-size: 7.5pt;
            line-height: 1.3;
        }

        table.legend thead {
            display: table-header-group;
        }

        table.legend tr {
            page-break-inside: avoid;
        }

        table.legend th {
            text-align: left;
            border-bottom: 0.6pt solid #999;
            padding: 1mm 1.5mm 1mm 0;
        }

        table.legend td {
            border-bottom: 0.3pt solid #ddd;
            padding: 1mm 1.5mm 1mm 0;
            vertical-align: top;
        }

        p.empty {
            margin: 6mm 0;
            font-size: 10pt;
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
            width: 38mm;
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

        /* Below the threshold: a lighter fill with a dark edge, readable in black and white. */
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

        .notes {
            font-size: 7.5pt;
            color: #666;
            margin: 0 0 1.5mm 0;
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
    {{ $document['meta']['type'] ?? __('Sem tipo') }} · {{ $document['meta']['purpose'] }} · {{ $document['issued_label'] }}
</p>
<p class="state">
    {{ $document['state']['label'] }}@if ($document['is_diagnostic']) · {{ __('Avaliação diagnóstica') }}@endif
</p>

@if ($document['is_empty'])
    <p class="empty">{{ $document['empty_message'] }}</p>
@else
    @foreach ($document['blocks'] as $index => $block)
        @php $hasQuotas = collect($block['columns'])->contains(fn (array $column): bool => $column['quota'] !== null); @endphp
        <div @if ($index > 0) style="page-break-before: always;" @endif>
            @if ($block['caption'])
                <h2>{{ $block['caption'] }}</h2>
            @endif

            <table class="grid">
                <thead>
                    @if ($block['group_spans'])
                        <tr class="groups">
                            <th colspan="2"></th>
                            @foreach ($block['group_spans'] as $span)
                                <th colspan="{{ $span['span'] }}">{{ $span['label'] }}</th>
                            @endforeach
                        </tr>
                    @endif
                    <tr>
                        <th class="left" style="width: {{ $numberWidth }};">{{ __('Nº') }}</th>
                        <th class="left" style="width: {{ $nameWidth }};">{{ __('Nome') }}</th>
                        @foreach ($block['columns'] as $column)
                            <th style="width: {{ $widthOf($column) }};">{{ $header($column) }}</th>
                        @endforeach
                    </tr>
                    @if ($hasQuotas)
                        <tr class="quota">
                            <th class="left" colspan="2">{{ __('Cotação') }}</th>
                            @foreach ($block['columns'] as $column)
                                <td>{{ $column['quota'] }}</td>
                            @endforeach
                        </tr>
                    @endif
                </thead>
                <tbody>
                    @foreach ($block['rows'] as $row)
                        <tr>
                            <td class="left">{{ $row['number'] }}</td>
                            <td class="name">{{ $row['name'] }}</td>
                            @foreach ($row['cells'] as $cell)
                                <td @class(['strong' => $cell['negative']])>{{ $cell['text'] }}@if ($cell['detail'])<br><span class="detail">{{ $cell['detail'] }}</span>@endif</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
@endif

@if ($document['charts'])
    @foreach ([$document['charts']['distribution'], $document['charts']['means'], $document['charts']['questions']] as $chart)
        <div class="chart">
            <h2>{{ $chart['title'] }}</h2>
            <p class="notes">{{ $chart['basis'] }}@if (isset($chart['n_label'])) · {{ $chart['n_label'] }}@endif</p>

            @if ($chart['bars'] === [])
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
            @if (isset($chart['note']))
                <p class="notes">{{ $chart['note'] }}</p>
            @endif
            @if ($chart['caution'])
                <p class="notes strong">{{ $chart['caution'] }}</p>
            @endif
        </div>
    @endforeach
@endif

@if ($document['legend']['questions'])
    <h2>{{ __('Questões') }}</h2>
    <table class="legend">
        <thead>
            <tr>
                <th style="width: 12mm;">{{ __('Código') }}</th>
                @if ($hasGroups)
                    <th style="width: 28mm;">{{ __('Grupo') }}</th>
                @endif
                <th>{{ __('Descrição') }}</th>
                <th style="width: 20mm;">{{ __('Cotação') }}</th>
                <th style="width: 42mm;">{{ __('Domínios') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($document['legend']['questions'] as $question)
                <tr>
                    <td class="strong">{{ $question['code'] }}</td>
                    @if ($hasGroups)
                        <td>{{ $question['group'] }}</td>
                    @endif
                    <td>{{ $question['label'] }}</td>
                    <td>{{ $question['quota'] }}@if ($question['bonus']) · {{ __('bónus') }}@endif</td>
                    <td>{{ $question['domains'] === [] ? '—' : implode(' · ', $question['domains']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if ($document['legend']['states'] !== [])
    <p class="notes">
        @foreach ($document['legend']['states'] as $state)
            <span class="strong">{{ $state['short'] }}</span> {{ $state['label'] }}@if (! $loop->last) &nbsp;·&nbsp; @endif
        @endforeach
    </p>
@endif
@foreach ($document['notes'] as $note)
    <p class="notes">{{ $note }}</p>
@endforeach
@if ($document['excluded_note'])
    <p class="notes">{{ $document['excluded_note'] }}</p>
@endif
<p class="notes">{{ $document['notice'] }}</p>

</body>
</html>
