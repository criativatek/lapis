{{--
    The printable report.

    WRITTEN FOR DOMPDF, WHICH IS NOT A BROWSER. It supports a useful subset of
    CSS 2.1 and almost nothing of what came after: no flexbox, no grid, no
    custom properties. Tables and floats are what it lays out reliably, so that
    is what this uses. Anything cleverer renders beautifully in a browser
    preview and collapses in the file the teacher actually sends (§49).

    PAGINATION IS THE POINT OF @page. The header repeats on every page through
    a fixed-position block, the footer carries the page number through dompdf's
    own counters, and `page-break-inside: avoid` keeps a heading from being
    orphaned at the bottom of a page from its first paragraph.
--}}
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <title>{{ $document['title'] }}</title>
    <style>
        @page {
            margin: 28mm 18mm 22mm 18mm;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10pt;
            line-height: 1.5;
            color: #1a1a1a;
        }

        /* Repeated on every page: fixed blocks live outside the flow in
           dompdf and are painted once per page. */
        #letterhead {
            position: fixed;
            top: -18mm;
            left: 0;
            right: 0;
            height: 16mm;
        }

        #letterhead td {
            vertical-align: top;
            font-size: 8pt;
            color: #555;
        }

        #letterhead .school {
            font-size: 10pt;
            font-weight: bold;
            color: #1a1a1a;
        }

        #footer {
            position: fixed;
            bottom: -14mm;
            left: 0;
            right: 0;
            font-size: 7.5pt;
            color: #666;
            border-top: 0.4pt solid #ccc;
            padding-top: 3mm;
        }

        #footer .page:after {
            content: counter(page) " / " counter(pages);
        }

        h1 {
            font-size: 14pt;
            margin: 0 0 2mm 0;
        }

        .subtitle {
            font-size: 9pt;
            color: #555;
            margin: 0 0 6mm 0;
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
            margin: 0 0 5mm 0;
        }

        h2 {
            font-size: 10.5pt;
            margin: 0 0 1.5mm 0;
            /* Never alone at the foot of a page. */
            page-break-after: avoid;
        }

        p {
            margin: 0 0 2mm 0;
            text-align: justify;
        }

        table.data {
            width: 100%;
            border-collapse: collapse;
            margin: 2mm 0 3mm 0;
            font-size: 8.5pt;
        }

        table.data caption {
            text-align: left;
            font-size: 8pt;
            color: #555;
            padding-bottom: 1mm;
        }

        table.data th {
            text-align: left;
            border-bottom: 0.6pt solid #999;
            padding: 1.2mm 2mm 1.2mm 0;
            font-weight: bold;
        }

        table.data td {
            border-bottom: 0.3pt solid #ddd;
            padding: 1.2mm 2mm 1.2mm 0;
            vertical-align: top;
        }

        table.data th.n, table.data td.n {
            text-align: right;
            padding-right: 0;
        }

        .signature {
            margin-top: 14mm;
            font-size: 9pt;
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
            @if ($document['identity']['logo'])
                <td style="width: 18mm;">
                    <img
                        src="data:{{ $document['identity']['logo']['mime'] }};base64,{{ base64_encode($document['identity']['logo']['data']) }}"
                        style="height: 14mm;"
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

<div id="footer">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="font-size: 7.5pt; color: #666;">
                {{ $document['identity']['footer_note'] ?? $document['identity']['name'] }}
            </td>
            <td style="text-align: right; font-size: 7.5pt; color: #666;">
                <span class="page"></span>
            </td>
        </tr>
    </table>
</div>

<h1>{{ $document['title'] }}</h1>
<p class="subtitle">{{ $document['subtitle'] }}</p>

@if ($document['draft_note'])
    <div class="draft">{{ $document['draft_note'] }}</div>
@endif

@foreach ($document['sections'] as $section)
    <section>
        <h2>{{ $section['heading'] }}</h2>

        @foreach ($section['paragraphs'] as $paragraph)
            {{-- Single newlines inside a paragraph are real: the listing
                 sections rely on them to keep one name per line. --}}
            <p>{!! nl2br(e($paragraph)) !!}</p>
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
        <div>
            Relatório finalizado
            @if ($document['meta']['finalized_by'])
                por {{ $document['meta']['finalized_by'] }}
            @endif
            @if ($document['meta']['finalized_at'])
                em {{ \Illuminate\Support\Carbon::parse($document['meta']['finalized_at'])->format('d/m/Y') }}
            @endif.
        </div>
    @elseif ($document['meta']['author'])
        <div>{{ $document['meta']['author'] }}</div>
    @endif

    <div class="line">O(A) professor(a)</div>
</div>

</body>
</html>
