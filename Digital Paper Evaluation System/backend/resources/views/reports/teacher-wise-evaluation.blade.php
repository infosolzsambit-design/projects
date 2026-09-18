<!DOCTYPE html>
{{--
    Shared by TeacherWiseEvaluationReportController::export() for BOTH
    download formats — rendered straight to HTML for the "Excel" download
    (Excel opens a plain <table> served with an Excel content-type/filename
    natively, no spreadsheet library needed for something this flat) and
    fed through dompdf for the PDF one.

    Deliberately ONE single <table> for the whole document (title, meta
    strip, column headers, data, footer — every row of it, via colspan),
    not several stacked tables, and every cell carries its own inline
    border/background/alignment rather than relying on a <style> block or
    CSS classes. Both of those are load-bearing, not just tidiness: Excel's
    own HTML-as-.xls import only reliably honours inline styles (a
    class-based <style> block is frequently dropped entirely), and several
    separate <table> elements each get their own independent auto column
    widths when Excel opens them — nothing then lines up against the data
    table below or spans its full width, which is exactly why the title/
    meta rows previously looked off-centre and border-less. dompdf is far
    more forgiving, but it renders this exact same markup just as well, so
    there's no reason to keep two versions.

    The one exception to "no <style> block" is @page below — Excel's own
    HTML import simply has no concept of a "page" at all and ignores it
    harmlessly, but dompdf specifically looks for @page to know how much
    margin to leave around this table on the actual printed PDF page (its
    own default is considerably roomier than this).
--}}
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 8px 12px 22px; }
</style>
</head>
<body style="margin: 0; font-family: Arial, Helvetica, sans-serif; color: #1f2937;">
<table border="1" cellspacing="0" cellpadding="4" style="width: 100%; border-collapse: collapse; border: 1px solid #1f3a82;">
    {{-- Title band — logo + site name, then the report name on its own
         row underneath, both centred across every column. --}}
    <tr>
        <td colspan="14" align="center" style="border: 1px solid #1f3a82; background-color: #2f56c0; padding: 8px 10px 4px;">
            @if ($logoDataUri)
                <img src="{{ $logoDataUri }}" height="28" style="vertical-align: middle; margin-right: 8px;" alt="" />
            @endif
            <span style="font-size: 14px; font-weight: bold; color: #ffffff; letter-spacing: 0.02em; vertical-align: middle;">{{ $siteTitle }}</span>
        </td>
    </tr>
    <tr>
        <td colspan="14" align="center" style="border: 1px solid #1f3a82; background-color: #24418f; padding: 5px 10px 7px; font-size: 10px; font-weight: bold; color: #ffffff; letter-spacing: 0.1em;">
            TEACHER WISE EVALUATION REPORT
        </td>
    </tr>

    {{-- Examination — the exam type this whole search was scoped to,
         called out on its own row right under the title so it's the
         first thing visible, not buried in the meta strip below. --}}
    <tr>
        <td colspan="14" align="center" style="border: 1px solid #1f3a82; background-color: #eef2fb; padding: 5px 10px; font-size: 10px; font-weight: bold; color: #1f3a82; letter-spacing: 0.03em;">
            Examination: {{ $examinationName ?? '—' }}
        </td>
    </tr>

    {{-- Meta strip — who generated this and when, on every export, so a
         downloaded copy can always be traced back to who pulled it. --}}
    <tr>
        <td colspan="7" align="left" style="border: 1px solid #dae2f0; background-color: #f0f3f8; padding: 4px 10px; font-size: 9px; color: #374151;">
            <strong>Printed By:</strong> {{ $printedBy }}
        </td>
        <td colspan="7" align="right" style="border: 1px solid #dae2f0; background-color: #f0f3f8; padding: 4px 10px; font-size: 9px; color: #374151;">
            <strong>Download Time:</strong> {{ $downloadedAt }}
        </td>
    </tr>

    {{-- Column headers — Mobile No sits right after Emp Code (both are
         "about the teacher"), Examination right after that (the same
         single value on every row, same reasoning Semester already
         repeats per row below), ahead of the subject/evaluation columns. --}}
    <tr>
        <th align="center" style="border: 1px solid #1f3a82; background-color: #2f56c0; color: #ffffff; padding: 4px 6px; font-size: 9px;">Teacher Name</th>
        <th align="center" style="border: 1px solid #1f3a82; background-color: #2f56c0; color: #ffffff; padding: 4px 6px; font-size: 9px;">Emp Code</th>
        <th align="center" style="border: 1px solid #1f3a82; background-color: #2f56c0; color: #ffffff; padding: 4px 6px; font-size: 9px;">Mobile No</th>
        <th align="center" style="border: 1px solid #1f3a82; background-color: #2f56c0; color: #ffffff; padding: 4px 6px; font-size: 9px;">Examination</th>
        <th align="center" style="border: 1px solid #1f3a82; background-color: #2f56c0; color: #ffffff; padding: 4px 6px; font-size: 9px;">Subject Name</th>
        <th align="center" style="border: 1px solid #1f3a82; background-color: #2f56c0; color: #ffffff; padding: 4px 6px; font-size: 9px;">Subject Code</th>
        <th align="center" style="border: 1px solid #1f3a82; background-color: #2f56c0; color: #ffffff; padding: 4px 6px; font-size: 9px;">Semester</th>
        <th align="center" style="border: 1px solid #1f3a82; background-color: #2f56c0; color: #ffffff; padding: 4px 6px; font-size: 9px;">Allotted Script</th>
        <th align="center" style="border: 1px solid #1f3a82; background-color: #2f56c0; color: #ffffff; padding: 4px 6px; font-size: 9px;">Allocation Date</th>
        <th align="center" style="border: 1px solid #1f3a82; background-color: #2f56c0; color: #ffffff; padding: 4px 6px; font-size: 9px;">Total Evaluated</th>
        <th align="center" style="border: 1px solid #1f3a82; background-color: #2f56c0; color: #ffffff; padding: 4px 6px; font-size: 9px;">Total Problem Script</th>
        <th align="center" style="border: 1px solid #1f3a82; background-color: #2f56c0; color: #ffffff; padding: 4px 6px; font-size: 9px;">Total Pending</th>
        <th align="center" style="border: 1px solid #1f3a82; background-color: #2f56c0; color: #ffffff; padding: 4px 6px; font-size: 9px;">Evaluation Start Date</th>
        <th align="center" style="border: 1px solid #1f3a82; background-color: #2f56c0; color: #ffffff; padding: 4px 6px; font-size: 9px;">Evaluation End Date</th>
    </tr>

    @forelse ($rows as $i => $row)
        @php $rowBg = $i % 2 === 1 ? '#f6f8fc' : '#ffffff'; @endphp
        <tr>
            <td align="left" style="border: 1px solid #d0d5dd; background-color: {{ $rowBg }}; padding: 3px 6px; font-size: 8.5px; font-weight: bold;">{{ $row['teacher_name'] }}</td>
            <td align="center" style="border: 1px solid #d0d5dd; background-color: {{ $rowBg }}; padding: 3px 6px; font-size: 8.5px;">{{ $row['emp_code'] ?? '—' }}</td>
            <td align="center" style="border: 1px solid #d0d5dd; background-color: {{ $rowBg }}; padding: 3px 6px; font-size: 8.5px;">{{ $row['mobile_no'] ?? '—' }}</td>
            <td align="center" style="border: 1px solid #d0d5dd; background-color: {{ $rowBg }}; padding: 3px 6px; font-size: 8.5px;">{{ $row['exam_type_name'] ?? '—' }}</td>
            <td align="left" style="border: 1px solid #d0d5dd; background-color: {{ $rowBg }}; padding: 3px 6px; font-size: 8.5px;">{{ $row['subject_name'] ?? '—' }}</td>
            <td align="center" style="border: 1px solid #d0d5dd; background-color: {{ $rowBg }}; padding: 3px 6px; font-size: 8.5px;">{{ $row['subject_code'] ?? '—' }}</td>
            <td align="center" style="border: 1px solid #d0d5dd; background-color: {{ $rowBg }}; padding: 3px 6px; font-size: 8.5px;">{{ $row['semester'] }}</td>
            <td align="center" style="border: 1px solid #d0d5dd; background-color: {{ $rowBg }}; padding: 3px 6px; font-size: 8.5px;">{{ $row['allotted_script'] }}</td>
            <td align="center" style="border: 1px solid #d0d5dd; background-color: {{ $rowBg }}; padding: 3px 6px; font-size: 8.5px;">{{ $row['allocation_date'] ?? '—' }}</td>
            <td align="center" style="border: 1px solid #d0d5dd; background-color: {{ $rowBg }}; padding: 3px 6px; font-size: 8.5px; color: #16a34a; font-weight: bold;">{{ $row['total_evaluated'] }}</td>
            <td align="center" style="border: 1px solid #d0d5dd; background-color: {{ $rowBg }}; padding: 3px 6px; font-size: 8.5px;">{{ $row['total_problem_script'] }}</td>
            <td align="center" style="border: 1px solid #d0d5dd; background-color: {{ $rowBg }}; padding: 3px 6px; font-size: 8.5px; color: #e81b26; font-weight: bold;">{{ $row['total_pending'] }}</td>
            <td align="center" style="border: 1px solid #d0d5dd; background-color: {{ $rowBg }}; padding: 3px 6px; font-size: 8.5px;">{{ $row['evaluation_start_date'] ?? '—' }}</td>
            <td align="center" style="border: 1px solid #d0d5dd; background-color: {{ $rowBg }}; padding: 3px 6px; font-size: 8.5px;">{{ $row['evaluation_end_date'] ?? '—' }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="14" align="center" style="border: 1px solid #d0d5dd; padding: 10px; font-size: 11px;">No matching evaluation activity found for this search.</td>
        </tr>
    @endforelse

    <tr>
        <td colspan="14" align="right" style="border: 1px solid #d0d5dd; background-color: #f9fafb; padding: 4px 10px; font-size: 9.5px; color: #6b7280;">
            {{ count($rows) }} record{{ count($rows) === 1 ? '' : 's' }} in this report.
        </td>
    </tr>
</table>
{{--
    PDF-only "Page X of Y" footer, stamped on every page. Must sit here,
    after all real content, not up near <body> — dompdf executes an inline
    <script type="text/php"> at the point it reaches that node during the
    single top-to-bottom render pass, and page_text() only stamps whatever
    pages already exist *at that moment*; placed near the top, that's just
    page 1, before later pages are even created, so both the "of N" count
    and every page past the first come out wrong. Down here, pagination is
    already finished, so page_text() correctly loops over every real page.
    Excel's HTML-as-.xls import has no concept of "pages" at all, so this
    is skipped there; see export()'s own comment on why isPhpEnabled is
    scoped to just this one PDF render. {PAGE_NUM}/{PAGE_COUNT} are
    dompdf's own placeholder tokens, substituted per page automatically.
--}}
@if ($isPdf)
<script type="text/php">
if (isset($pdf)) {
    // page_text() substitutes {PAGE_NUM}/{PAGE_COUNT} only *inside itself*,
    // after any width already measured against the raw placeholder text —
    // measuring that unsubstituted string (far longer than the real "Page
    // 1 of 1") threw the centering off. page_script() instead hands back
    // the real page number/count per page, so the width used to center it
    // is measured against the actual text that gets drawn.
    $pdf->page_script(function ($pageNumber, $pageCount, $canvas, $fontMetrics) {
        $font = $fontMetrics->getFont('Helvetica', 'bold');
        $size = 8;
        $text = "Page {$pageNumber} of {$pageCount}";
        $width = $fontMetrics->getTextWidth($text, $font, $size);
        $canvas->text(($canvas->get_width() - $width) / 2, $canvas->get_height() - 16, $text, $font, $size, [0.12, 0.16, 0.22]);
    });
}
</script>
@endif
</body>
</html>
