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
    /* Report logo top-left, Organization Logo (General Settings) top-centre. */
    table.header { width: 100%; border-collapse: collapse; border-bottom: 2px solid #1f3a82; margin-bottom: 10px; }
    table.header td { padding: 0 0 6px; vertical-align: middle; height: 42px; }
    table.header .logo { height: 32px; }
    table.header .org-logo { height: 38px; }
    .title { text-align: center; font-size: 13px; font-weight: bold; letter-spacing: 0.08em; background-color: #2f56c0; color: #ffffff; padding: 6px; margin-bottom: 10px; }
    table.fields { width: 100%; border-collapse: collapse; margin-bottom: 12px; border: 1px solid #c7cfdb; }
    table.fields td { padding: 5px 8px; font-size: 10.5px; vertical-align: top; border-bottom: 1px solid #e3e8f0; }
    table.fields tr:last-child td { border-bottom: none; }
    table.fields td.label { width: 110px; font-weight: bold; color: #1f3a82; background-color: #f0f3f8; font-size: 9.5px; letter-spacing: 0.03em; }
    table.fields td.value { width: 39%; color: #111827; }
    table.fields td.value.strong { font-weight: bold; }
    /* Evaluator block — pinned to the bottom of the sheet's last page
       (absolute, so dompdf places it on the page where it falls in the
       flow). The spacer before it reserves that much room, so a long
       question grid pushes it onto a fresh page instead of overlapping. */
    .evaluator-spacer { height: 90px; }
    /* Measured from the top of the page's content area (A4 at dompdf's
       96 dpi, minus the @page margins) — dompdf places "bottom" unreliably. */
    .evaluator-wrap { position: absolute; left: 0; right: 0; top: 1003px; height: 70px; }
    table.evaluator { width: 100%; border-collapse: collapse; }
    table.evaluator td { padding: 8px 4px; vertical-align: bottom; font-size: 10px; }
    table.evaluator .caption { font-size: 8.5px; font-weight: bold; color: #1f3a82; letter-spacing: 0.06em; margin-bottom: 4px; }
    table.evaluator .name { font-size: 11.5px; font-weight: bold; color: #111827; }
    table.evaluator .sub { font-size: 9px; color: #4b5563; margin-top: 2px; }
    table.evaluator .sign-box { height: 46px; text-align: center; }
    table.evaluator .sign-box img { max-height: 44px; max-width: 170px; }
    table.evaluator .sign-line { border-top: 1px solid #374151; margin: 2px 12px 0; padding-top: 3px; text-align: center; font-size: 8.5px; color: #374151; }
    table.evaluator .no-sign { font-size: 9px; color: #9ca3af; font-style: italic; line-height: 46px; }
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
        <table class="header">
            <tr>
                <td style="width: 22%; text-align: left;">
                    @if ($logoDataUri)
                        <img class="logo" src="{{ $logoDataUri }}" alt="" />
                    @endif
                </td>
                <td style="width: 56%; text-align: center;">
                    @if ($organizationLogoDataUri ?? null)
                        <img class="org-logo" src="{{ $organizationLogoDataUri }}" alt="" />
                    @endif
                </td>
                <td style="width: 22%;"></td>
            </tr>
        </table>
        <div class="title">EVALUATION ANSWER BOOK</div>

        {{-- Student and exam identity — label/value pairs, two per row. --}}
        <table class="fields">
            <tr>
                <td class="label">STUDENT NAME</td><td class="value strong">{{ $sheet['student_name'] ?: '—' }}</td>
                <td class="label">REGISTRATION NO</td><td class="value strong">{{ $sheet['registration_no'] ?: '—' }}</td>
            </tr>
            <tr>
                <td class="label">EXAMINATION</td><td class="value">{{ $sheet['exam_type'] ?? '—' }}</td>
                <td class="label">SCRIPT QR</td><td class="value">{{ $sheet['script_code'] ?? '—' }}</td>
            </tr>
            <tr>
                <td class="label">COURSE</td><td class="value">{{ \App\Helpers\CourseLabel::withType($sheet['course_name'] ?? '—', $sheet['course_type'] ?? null) }}</td>
                <td class="label">COURSE CODE</td><td class="value">{{ $sheet['course_code'] ?? '—' }}</td>
            </tr>
            <tr>
                <td class="label">DEPARTMENT</td><td class="value" colspan="3">{{ $sheet['department_name'] ?: '—' }}</td>
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
                        <td>{{ $q['obtained'] ?? '-' }}</td>
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

        {{-- Evaluator — who evaluated it, their e-signature over a
             signature line, and when the evaluation ended — one line,
             bottom-aligned; sits at the bottom of the page, not straight
             after the grid. (Printed time is in the page footer.) --}}
        <div class="evaluator-spacer"></div>
        <div class="evaluator-wrap">
        <table class="evaluator">
            <tr>
                <td style="width: 36%;">
                    <div class="caption">EVALUATED BY</div>
                    <div class="name">{{ $sheet['evaluated_by'] ?: '—' }}</div>
                    <div class="sub">
                        {{ $sheet['evaluator_designation'] }}{{ $sheet['evaluator_designation'] && $sheet['evaluator_emp_code'] ? ' · ' : '' }}{{ $sheet['evaluator_emp_code'] ? 'Emp Code: '.$sheet['evaluator_emp_code'] : '' }}&nbsp;
                    </div>
                </td>
                <td style="width: 34%;">
                    <div class="sign-box">
                        @if ($sheet['evaluator_esign'])
                            <img src="{{ $sheet['evaluator_esign'] }}" alt="" />
                        @else
                            <span class="no-sign">No e-signature on file</span>
                        @endif
                    </div>
                    <div class="sign-line">Signature of Evaluator</div>
                </td>
                {{-- Same three-line shape as Evaluated By (empty last line),
                     so the name and the time sit on the same line. --}}
                <td style="width: 30%; text-align: right;">
                    <div class="caption">EVALUATION END TIME</div>
                    <div class="name">{{ $sheet['evaluated_at'] ?? '—' }}</div>
                    <div class="sub">&nbsp;</div>
                </td>
            </tr>
        </table>
        </div>
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
{{-- Same footer line: "Page X of Y" centred (left off when
     $pageNumbers is false) and the printed time in the bottom-right corner. --}}
<script type="text/php">
if (isset($pdf)) {
    $showPageNumbers = {{ ($pageNumbers ?? true) ? 'true' : 'false' }};
    $printed = {!! json_encode('Printed Date & Time: '.($printedAt ?? '')) !!};
    $pdf->page_script(function ($pageNumber, $pageCount, $canvas, $fontMetrics) use ($showPageNumbers, $printed) {
        $y = $canvas->get_height() - 16;
        $bold = $fontMetrics->getFont('Helvetica', 'bold');
        $size = 8;
        if ($showPageNumbers) {
            $text = "Page {$pageNumber} of {$pageCount}";
            $width = $fontMetrics->getTextWidth($text, $bold, $size);
            $canvas->text(($canvas->get_width() - $width) / 2, $y, $text, $bold, $size, [0.12, 0.16, 0.22]);
        }
        $regular = $fontMetrics->getFont('Helvetica', 'normal');
        $width = $fontMetrics->getTextWidth($printed, $regular, $size);
        $canvas->text($canvas->get_width() - $width - 14, $y, $printed, $regular, $size, [0.29, 0.33, 0.39]);
    });
}
</script>
</body>
</html>
