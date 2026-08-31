{{--
    The printable report.

    WRITTEN FOR DOMPDF, WHICH IS NOT A BROWSER. It supports a useful subset of
    CSS 2.1 and almost nothing of what came after: no flexbox, no grid, no
    custom properties. Tables and floats are what it lays out reliably, so that
    is what this uses. Anything cleverer renders beautifully in a browser
    preview and collapses in the file the teacher actually sends (§49).

    THE PAGE NUMBER IS NOT IN HERE. `counter(pages)` is CSS dompdf does not
    implement — its own user stylesheet gives it a `page` counter and there is
    no `pages` counter anywhere in the engine, so «1 / 0» was never a layout
    bug: it was a total that did not exist. The footer's page label is drawn by
    PdfRenderer after the render, which is the first moment the page count is a
    real number (§49).

    KEEPING THINGS TOGETHER IS THE POINT OF THE BREAK RULES. The letterhead
    repeats on every page through a fixed-position block; `page-break-after:
    avoid` keeps a heading with what follows it, and `page-break-inside: avoid`
    keeps a table row from being split down the middle.
--}}
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <title>{{ $document['title'] }}</title>
    <style>
        /* Room for a three-line letterhead above and one footer line below,
           with enough clearance that neither can meet the text. */
        @page {
            margin: 30mm 20mm 24mm 20mm;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10pt;
            line-height: 1.55;
            color: #1a1a1a;
        }

        /* Repeated on every page: fixed blocks live outside the flow in
           dompdf and are painted once per page. */
        #letterhead {
            position: fixed;
            top: -20mm;
            left: 0;
            right: 0;
            height: 16mm;
        }

        #letterhead td {
            vertical-align: top;
            font-size: 7.5pt;
            line-height: 1.35;
            color: #666;
        }

        /* Smaller than the report's own title: the school identifies the
           document, it is not what the document is called (§50). */
        #letterhead .school {
            font-size: 9pt;
            font-weight: bold;
            color: #333;
            padding-bottom: 0.6mm;
        }

        #footer {
            position: fixed;
            bottom: -16mm;
            left: 0;
            right: 0;
            font-size: 7.5pt;
            color: #666;
            border-top: 0.4pt solid #ccc;
            padding-top: 2.5mm;
        }

        h1 {
            font-size: 15pt;
            line-height: 1.25;
            margin: 0 0 1.5mm 0;
        }

        .subtitle {
            font-size: 9pt;
            color: #555;
            margin: 0 0 7mm 0;
        }

        .draft {
            border: 0.8pt solid #b45309;
            background: #fffbeb;
            color: #92400e;
            padding: 2.5mm 3mm;
            margin: 0 0 6mm 0;
            font-size: 8.5pt;
            font-weight: bold;
        }

        section {
            margin: 0 0 6mm 0;
        }

        h2 {
            font-size: 10.5pt;
            margin: 0 0 2mm 0;
            /* Never alone at the foot of a page. */
            page-break-after: avoid;
        }

        p {
            margin: 0 0 2.5mm 0;
            text-align: justify;
            /* A single line stranded on its own page reads as a rendering
               accident, which on a document a school sends out is
               indistinguishable from one. */
            orphans: 2;
            widows: 2;
        }

        /* A lead-in belongs to the list under it and never ends a page
           alone. */
        p.lead-in {
            margin-bottom: 1.2mm;
            page-break-after: avoid;
        }

        ul {
            margin: 0 0 2.5mm 0;
            padding-left: 5mm;
        }

        li {
            margin-bottom: 1mm;
            /* An item split across two pages is a rendering accident on a
               document a school sends out. */
            page-break-inside: avoid;
        }

        table.data {
            width: 100%;
            border-collapse: collapse;
            margin: 2.5mm 0 4mm 0;
            font-size: 8.5pt;
        }

        table.data caption {
            text-align: left;
            font-size: 8pt;
            color: #555;
            padding-bottom: 1.2mm;
            /* The caption belongs to the table under it. */
            page-break-after: avoid;
        }

        /* The header row repeats when a long table crosses a page. */
        table.data thead {
            display: table-header-group;
        }

        table.data tr {
            /* A row split across two pages puts a student's name on one page
               and their figure on the next. */
            page-break-inside: avoid;
        }

        table.data th {
            text-align: left;
            border-bottom: 0.6pt solid #999;
            padding: 1.4mm 2mm 1.4mm 0;
            font-weight: bold;
        }

        table.data td {
            border-bottom: 0.3pt solid #ddd;
            padding: 1.4mm 2mm 1.4mm 0;
            vertical-align: top;
        }

        table.data th.n, table.data td.n {
            text-align: right;
            padding-right: 0;
        }

        .signature {
            margin-top: 14mm;
            font-size: 9pt;
            page-break-inside: avoid;
        }

        .signature .line {
            border-top: 0.4pt solid #555;
            width: 65mm;
            margin-top: 12mm;
            padding-top: 1.5mm;
        }
    </style>
</head>
<body>

<div id="letterhead">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            {{-- Only when the report asked for one. No logo means no column,
                 so nothing reserves space for an image that is not there. --}}
            @if ($document['identity']['logo'])
                <td style="width: 16mm;">
                    <img
                        src="data:{{ $document['identity']['logo']['mime'] }};base64,{{ base64_encode($document['identity']['logo']['data']) }}"
                        style="height: 12mm;"
                        alt=""
                    >
                </td>
            @endif
            <td>
                <div class="school">{{ $document['identity']['name'] }}</div>
                @foreach ($document['identity']['header_lines'] as $line)
                    <div>{{ $line }}</div>
                @endforeach
            </td>
        </tr>
    </table>
</div>

{{-- The note only. «Página N / T» is drawn by the renderer, which is the only
     thing that knows T. --}}
<div id="footer">
    {{ $document['identity']['footer_note'] ?? $document['identity']['name'] }}
</div>

<h1>{{ $document['title'] }}</h1>
@if ($document['subtitle'] !== '')
    <p class="subtitle">{{ $document['subtitle'] }}</p>
@endif

@if ($document['draft_note'])
    <div class="draft">{{ $document['draft_note'] }}</div>
@endif

@foreach ($document['sections'] as $section)
    <section>
        <h2>{{ $section['heading'] }}</h2>

        @foreach ($section['blocks'] as $block)
            @if ($block['kind'] === 'list')
                {{-- A real list, not a paragraph with dashes typed into it
                     (§10). The lead-in keeps its own sentence above them. --}}
                @if ($block['lead'])
                    <p class="lead-in">{{ $block['lead'] }}</p>
                @endif
                <ul>
                    @foreach ($block['items'] as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            @else
                {{-- Single newlines inside a paragraph are real: a teacher who
                     typed a line break meant one. --}}
                <p>{!! nl2br(e($block['text'])) !!}</p>
            @endif
        @endforeach

        @foreach ($section['tables'] as $table)
            <table class="data">
                @if ($table['caption'])
                    <caption>{{ $table['caption'] }}</caption>
                @endif
                <thead>
                    <tr>
                        @foreach ($table['headers'] as $index => $header)
                            <th class="{{ $index > 0 && count($table['headers']) > 2 ? 'n' : '' }}">{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($table['rows'] as $row)
                        <tr>
                            @foreach ($row as $index => $cell)
                                <td class="{{ $index > 0 && count($table['headers']) > 2 ? 'n' : '' }}">{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    </section>
@endforeach

<div class="signature">
    @if ($document['meta']['status'] === 'finalized')
        {{-- Composed as one string rather than assembled out of @if blocks: the
             newline before the full stop printed «em 19/08/2026 .» --}}
        <div>{{ $document['meta']['closing'] }}</div>
    @elseif ($document['meta']['author'])
        <div>{{ $document['meta']['author'] }}</div>
    @endif

    <div class="line">{{ $document['meta']['signature_caption'] }}</div>
</div>

</body>
</html>
