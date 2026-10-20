{{--
    The school's letterhead and footer — the CSS half.

    SHARED BY EVERY PRINTED DOCUMENT (the report and the class documents), so the
    school's timbre looks the same on a Relatório and on a Mapa de Medidas
    Educativas. Included INSIDE a `<style>` element by the document's own
    template; the document adds its own rules after it.

    Written for dompdf: fixed blocks live outside the flow and are painted once
    per page, which is how the letterhead and the footer repeat. The page
    number is not here — DompdfPrinter draws it, because only it knows the total.

    The measures come from LetterheadLayout (`$layout`, Standard when omitted), the
    same enum DompdfPrinter reads to place the page label.
--}}
@php
    $layout = $layout ?? \App\Services\Documents\Pdf\LetterheadLayout::Standard;
@endphp
/* Room for a four-line letterhead above and one footer line below,
   with enough clearance that neither can meet the text. The top grew
   from 30mm after SUP-DTQLDG: the timbre and the body need daylight
   between them, on every page. */
@page {
    margin: {{ $layout->pageMargin() }};
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
    top: {{ $layout->letterheadTop() }};
    left: 0;
    right: 0;
    height: {{ $layout->letterheadHeight() }};
    /* A régua fina fecha o timbre — papel timbrado, não um
       parágrafo perdido no topo. */
    border-bottom: 0.4pt solid #ccc;
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
    bottom: {{ $layout->footerBottom() }};
    left: 0;
    right: 0;
    font-size: 7.5pt;
    color: #666;
    border-top: 0.4pt solid #ccc;
    padding-top: 2.5mm;
}
