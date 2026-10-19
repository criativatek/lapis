{{--
    The Mapa de Medidas Educativas, printed.

    WRITTEN FOR DOMPDF, WHICH IS NOT A BROWSER: tables and floats, no flexbox,
    no grid, no custom properties. It renders the SAME structure the on-screen
    preview draws (ClassMeasuresMap) and reads nothing else — which is why it
    cannot print a field the structure does not carry.

    THE TIMBRE IS THE SHARED ONE. The letterhead and the footer come from the
    partials the report template uses, so the school's paper looks the same on
    every document it sends. The «Página N / T» label is drawn by DompdfPrinter.

    THE HEADER ROW REPEATS. A map of a whole class crosses pages, and a page of
    «Implementadas | A implementar» cells with no heading is unreadable;
    `page-break-inside: avoid` keeps a student's row in one piece.
--}}
@php
    $showImplemented = $document['columns']['implemented'];
    $showPlanned = $document['columns']['planned'];
    $columnCount = ($showImplemented ? 1 : 0) + ($showPlanned ? 1 : 0);
    $columnWidth = $columnCount === 2 ? '35%' : '70%';
    $hasClassWide = $document['class_wide']['implemented'] !== [] || $document['class_wide']['planned'] !== [];
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
            font-size: 17pt;
            font-weight: normal;
            line-height: 1.25;
            margin: 0 0 1.5mm 0;
        }

        .subtitle {
            font-size: 9pt;
            color: #555;
            margin: 0 0 1mm 0;
        }

        .issued {
            font-size: 8pt;
            color: #666;
            margin: 0 0 5mm 0;
        }

        .summary {
            font-size: 10pt;
            font-weight: bold;
            margin: 0 0 6mm 0;
        }

        h2 {
            font-size: 10.5pt;
            margin: 0 0 2mm 0;
            page-break-after: avoid;
        }

        table.map {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 6mm 0;
            font-size: 8.5pt;
        }

        /* The header row repeats when a long table crosses a page. */
        table.map thead {
            display: table-header-group;
        }

        table.map tr {
            /* A row split across two pages puts a student's name on one page
               and half their measures on the next. */
            page-break-inside: avoid;
        }

        table.map th {
            text-align: left;
            border-bottom: 0.6pt solid #999;
            padding: 1.4mm 2mm 1.4mm 0;
            font-weight: bold;
        }

        table.map td {
            border-bottom: 0.3pt solid #ddd;
            padding: 1.4mm 2mm 1.4mm 0;
            vertical-align: top;
        }

        td.student {
            font-weight: bold;
        }

        ul.items {
            margin: 0;
            padding-left: 3.5mm;
        }

        ul.items li {
            margin-bottom: 1.2mm;
        }

        .item-label {
            font-weight: bold;
        }

        .item-detail {
            font-size: 7.5pt;
            color: #666;
        }

        .none {
            color: #888;
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
    </style>
</head>
<body>

@include('documents.partials.letterhead', ['identity' => $document['identity']])

<h1>{{ $document['title'] }}</h1>
<p class="subtitle">{{ $document['subtitle'] }}</p>
<p class="issued">{{ $document['issued_label'] }}</p>
<p class="summary">{{ $document['summary_line'] }}</p>

{{-- One cell of items, shared by the class-wide block and the student rows. --}}
@php
    $renderItems = function (array $items): string {
        if ($items === []) {
            return '<span class="none">—</span>';
        }

        $html = '<ul class="items">';

        foreach ($items as $item) {
            $detail = array_filter([
                $item['framing'],
                $item['status'],
                $item['group'] ? __('em grupo') : null,
            ]);

            $html .= '<li><span class="item-label">'.e($item['label']).'</span>';

            if ($detail !== []) {
                $html .= '<br><span class="item-detail">'.e(implode(' · ', $detail)).'</span>';
            }

            $html .= '</li>';
        }

        return $html.'</ul>';
    };
@endphp

@if ($document['is_empty'])
    <p class="empty">{{ $document['empty_message'] }}</p>
@else
    @if ($hasClassWide)
        <h2>{{ __('Medidas dirigidas a toda a turma') }}</h2>
        <table class="map">
            <thead>
                <tr>
                    @if ($showImplemented)
                        <th style="width: 50%;">{{ $document['column_labels']['implemented'] }}</th>
                    @endif
                    @if ($showPlanned)
                        <th style="width: 50%;">{{ $document['column_labels']['planned'] }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                <tr>
                    @if ($showImplemented)
                        <td>{!! $renderItems($document['class_wide']['implemented']) !!}</td>
                    @endif
                    @if ($showPlanned)
                        <td>{!! $renderItems($document['class_wide']['planned']) !!}</td>
                    @endif
                </tr>
            </tbody>
        </table>
    @endif

    @if ($document['students'] !== [])
        <table class="map">
            <thead>
                <tr>
                    <th style="width: 30%;">{{ __('Aluno') }}</th>
                    @if ($showImplemented)
                        <th style="width: {{ $columnWidth }};">{{ $document['column_labels']['implemented'] }}</th>
                    @endif
                    @if ($showPlanned)
                        <th style="width: {{ $columnWidth }};">{{ $document['column_labels']['planned'] }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($document['students'] as $student)
                    <tr>
                        <td class="student">
                            {{ $student['number'] !== null ? __('N.º').' '.$student['number'].' · '.$student['name'] : $student['name'] }}
                        </td>
                        @if ($showImplemented)
                            <td>{!! $renderItems($student['implemented']) !!}</td>
                        @endif
                        @if ($showPlanned)
                            <td>{!! $renderItems($student['planned']) !!}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endif

<p class="notes">{{ $document['criteria'] }}</p>
@if ($document['excluded_note'])
    <p class="notes">{{ $document['excluded_note'] }}</p>
@endif
<p class="notes">{{ $document['notice'] }}</p>

</body>
</html>
