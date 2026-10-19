{{--
    The school's letterhead and footer — the markup half.

    Expects `$identity`: `name`, `header_lines`, `footer_note` and, optionally,
    `logo` (`mime` and `data`, the raw bytes). Paired with
    `documents.partials.letterhead-styles`, which positions both blocks.
--}}
<div id="letterhead">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            {{-- Only when the document has one. No logo means no column,
                 so nothing reserves space for an image that is not there. --}}
            @if ($identity['logo'] ?? null)
                <td style="width: 16mm;">
                    <img
                        src="data:{{ $identity['logo']['mime'] }};base64,{{ base64_encode($identity['logo']['data']) }}"
                        style="height: 12mm;"
                        alt=""
                    >
                </td>
            @endif
            <td>
                <div class="school">{{ $identity['name'] }}</div>
                @foreach ($identity['header_lines'] as $line)
                    <div>{{ $line }}</div>
                @endforeach
            </td>
        </tr>
    </table>
</div>

{{-- The note only. «Página N / T» is drawn by DompdfPrinter, which is the only
     thing that knows T. --}}
<div id="footer">
    {{ $identity['footer_note'] ?? $identity['name'] }}
</div>
