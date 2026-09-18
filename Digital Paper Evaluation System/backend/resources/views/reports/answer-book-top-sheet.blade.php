<!DOCTYPE html>
{{--
    Shared by AnswerBookTopSheetReportController's three endpoints — see
    that controller's own docblock for what each one renders this into
    (a single inline "View", one combined multi-sheet PDF, or once per
    sheet inside a ZIP). Format example: designed_files/top_sheet.jpeg.

    Unlike the Teacher Wise report's own Blade view, this one is NEVER
    served as raw HTML-opened-as-Excel — every render here goes through
    dompdf — so there's no reason to avoid a normal <style> block/CSS
    classes the way that one has to.

    One <section class="sheet"> per answer sheet, each forced onto its
    own printed page via page-break-after — except the very last section,
    which would otherwise leave one extra trailing blank page.
--}}
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 14px 16px 26px; }
    body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 10.5px; }
    .header { width: 100%; border-bottom: 2px solid #1f3a82; padding-bottom: 6px; margin-bottom: 10px; }
    .header img { height: 32px; vertical-align: middle; margin-right: 8px; }
    .header .brand { font-size: 15px; font-weight: bold; color: #1f3a82; vertical-align: middle; }
    .title { text-align: center; font-size: 13px; font-weight: bold; letter-spacing: 0.08em; background-color: #2f56c0; color: #ffffff; padding: 6px; margin-bottom: 10px; }
    table.fields { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    table.fields td { padding: 3px 4px; font-size: 10.5px; vertical-align: top; }
    table.fields td.label { width: 130px; font-weight: bold; color: #374151; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid th, table.grid td { border: 1px solid #c7cfdb; padding: 4px 6px; font-size: 10px; }
    table.grid th { background-color: #2f56c0; color: #ffffff; text-align: center; }
    table.grid td { text-align: center; }
    table.grid td.qsn { text-align: left; font-weight: bold; }
    table.grid tfoot td { font-weight: bold; background-color: #f0f3f8; }
</style>
</head>
<body>
@foreach ($sheets as $sheet)
    <section @if (! $loop->last) style="page-break-after: always;" @endif>
        <div class="header">
            @if ($logoDataUri)
                <img src="{{ $logoDataUri }}" alt="" />
            @endif
            <span class="brand">{{ $siteTitle }}</span>
        </div>
        <div class="title">EVALUATION ANSWER BOOK</div>

        {{-- Deliberately just these four — see the controller's own
             sheetData() docblock for why the top sheet carries no
             roll/name/registration/packet identity at all. --}}
        <table class="fields">
            <tr>
                <td class="label">EXAMINATION</td><td>{{ $sheet['exam_type'] ?? '—' }}</td>
                <td class="label">SCRIPT QR</td><td>{{ $sheet['script_code'] ?? '—' }}</td>
            </tr>
            <tr>
                <td class="label">COURSE</td><td>{{ $sheet['course_name'] ?? '—' }}</td>
                <td class="label">COURSE CODE</td><td>{{ $sheet['course_code'] ?? '—' }}</td>
            </tr>
        </table>

        <table class="grid">
            <thead>
                <tr>
                    <th style="width: 45%;">Question Number</th>
                    <th style="width: 27.5%;">Marks</th>
                    <th style="width: 27.5%;">Obtained Marks</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sheet['questions'] as $q)
                    <tr>
                        <td class="qsn">{{ $q['label'] }}</td>
                        <td>{{ $q['max_marks'] ?? '—' }}</td>
                        <td>{{ $q['obtained'] ?? 'N/A' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">No question structure found for this paper.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <td class="qsn">Total</td>
                    <td>{{ $sheet['full_marks'] ?? '—' }}</td>
                    <td>{{ $sheet['total_marks'] ?? '—' }}</td>
                </tr>
            </tfoot>
        </table>
    </section>
@endforeach

{{--
    "Page X of Y" footer stamped on every page — must sit here, after all
    real content, not up near <body>; see teacher-wise-evaluation.blade.
    php's own version of this same comment for exactly why placement
    matters (page_text()/page_script() only know about pages that already
    exist by the time this script tag is reached during dompdf's single
    top-to-bottom render pass).
--}}
<script type="text/php">
if (isset($pdf)) {
    $pdf->page_script(function ($pageNumber, $pageCount, $canvas, $fontMetrics) {
        $font = $fontMetrics->getFont('Helvetica', 'bold');
        $size = 8;
        $text = "Page {$pageNumber} of {$pageCount}";
        $width = $fontMetrics->getTextWidth($text, $font, $size);
        $canvas->text(($canvas->get_width() - $width) / 2, $canvas->get_height() - 16, $text, $font, $size, [0.12, 0.16, 0.22]);
    });
}
</script>
</body>
</html>
