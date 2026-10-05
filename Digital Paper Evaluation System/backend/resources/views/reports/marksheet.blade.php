<!DOCTYPE html>
{{--
    GenerateMarksheetController::export() — one course's marksheet, for BOTH
    downloads: served as-is for Excel (an HTML <table> Excel opens natively)
    and through dompdf for the PDF. Same one-table, inline-styles-only
    layout as teacher-wise-evaluation.blade.php, for the same reasons
    (Excel's HTML import only honours inline styles, and separate tables
    don't line up); @page is only read by dompdf.
--}}
@php
    $cols = 16;
    $th = 'border: 1px solid #1f3a82; background-color: #2f56c0; color: #ffffff; padding: 4px 6px; font-size: 9px;';
@endphp
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 8px 12px 22px; }
</style>
</head>
<body style="margin: 0; font-family: Arial, Helvetica, sans-serif; color: #1f2937;">
<table border="1" cellspacing="0" cellpadding="4" style="width: 100%; border-collapse: collapse; border: 1px solid #1f3a82;">
    @include('reports.partials.logo-header', ['cols' => $cols])
    <tr>
        <td colspan="{{ $cols }}" align="center" style="border: 1px solid #1f3a82; background-color: #24418f; padding: 5px 10px 7px; font-size: 10px; font-weight: bold; color: #ffffff; letter-spacing: 0.1em;">
            MARKSHEET
        </td>
    </tr>
    <tr>
        <td colspan="{{ $cols }}" align="center" style="border: 1px solid #1f3a82; background-color: #eef2fb; padding: 5px 10px; font-size: 10px; font-weight: bold; color: #1f3a82; letter-spacing: 0.03em;">
            Examination: {{ $examinationName ?? '—' }}&nbsp;&nbsp;|&nbsp;&nbsp;Course: {{ $courseLabel ?? '—' }}
        </td>
    </tr>
    <tr>
        <td colspan="8" align="left" style="border: 1px solid #dae2f0; background-color: #f0f3f8; padding: 4px 10px; font-size: 9px; color: #374151;">
            <strong>Printed By:</strong> {{ $printedBy }}
        </td>
        <td colspan="8" align="right" style="border: 1px solid #dae2f0; background-color: #f0f3f8; padding: 4px 10px; font-size: 9px; color: #374151;">
            <strong>Download Time:</strong> {{ $downloadedAt }}
        </td>
    </tr>

    <tr>
        <th align="center" style="{{ $th }}">Program Name</th>
        <th align="center" style="{{ $th }}">Department</th>
        <th align="center" style="{{ $th }}">Course Name</th>
        <th align="center" style="{{ $th }}">Course Code</th>
        <th align="center" style="{{ $th }}">Exam Term</th>
        <th align="center" style="{{ $th }}">Exam Type</th>
        <th align="center" style="{{ $th }}">Semester</th>
        <th align="center" style="{{ $th }}">Barcode</th>
        <th align="center" style="{{ $th }}">University Code</th>
        <th align="center" style="{{ $th }}">Student Name</th>
        <th align="center" style="{{ $th }}">Roll Number</th>
        <th align="center" style="{{ $th }}">Registration Number</th>
        <th align="center" style="{{ $th }}">Total Marks</th>
        <th align="center" style="{{ $th }}">Obtained Marks</th>
        <th align="center" style="{{ $th }}">Lock-in Time</th>
        <th align="center" style="{{ $th }}">Type</th>
    </tr>

    @forelse ($rows as $i => $row)
        @php
            $bg = $i % 2 === 1 ? '#f6f8fc' : '#ffffff';
            $td = "border: 1px solid #d0d5dd; background-color: {$bg}; padding: 3px 6px; font-size: 8.5px;";
            // Long numeric codes kept as text so Excel doesn't show them in
            // scientific notation or drop leading zeros.
            $text = "mso-number-format:'\@';";
        @endphp
        <tr>
            <td align="left" style="{{ $td }}">{{ \App\Helpers\ProgramLabel::display($row['program_name'] ?? null) ?? '—' }}</td>
            {{-- The packet's own department (chosen on Answer Sheet Upload). --}}
            <td align="left" style="{{ $td }}">{{ $row['department_name'] ?: '—' }}</td>
            <td align="left" style="{{ $td }}">{{ $row['course_name'] ?? '—' }}</td>
            <td align="center" style="{{ $td }}">{{ $row['course_code'] ?? '—' }}</td>
            <td align="center" style="{{ $td }}">{{ $row['exam_term_name'] ?? '—' }}</td>
            <td align="center" style="{{ $td }}">{{ $row['exam_type_name'] ?? '—' }}</td>
            <td align="center" style="{{ $td }}">{{ $row['semester'] }}</td>
            <td align="center" style="{{ $td }} {{ $text }}">{{ $row['barcode'] ?? '—' }}</td>
            <td align="center" style="{{ $td }} {{ $text }}">{{ $universityCode ?: '—' }}</td>
            <td align="left" style="{{ $td }} font-weight: bold;">{{ $row['student_name'] ?? '—' }}</td>
            <td align="center" style="{{ $td }} {{ $text }}">{{ $row['roll_no'] ?? '—' }}</td>
            <td align="center" style="{{ $td }} {{ $text }}">{{ $row['registration_no'] ?? '—' }}</td>
            <td align="center" style="{{ $td }}">{{ $row['total_marks'] ?? '—' }}</td>
            <td align="center" style="{{ $td }} font-weight: bold; color: #16a34a;">{{ $row['obtained_marks'] ?? '—' }}</td>
            <td align="center" style="{{ $td }}">{{ $row['lock_in_time'] ?? '—' }}</td>
            {{-- The course's own type (Courses master), e.g. Theory / Practical. --}}
            <td align="center" style="{{ $td }}">{{ $row['course_type'] ?: '—' }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="{{ $cols }}" align="center" style="border: 1px solid #d0d5dd; padding: 10px; font-size: 11px;">No answer sheets found.</td>
        </tr>
    @endforelse

    <tr>
        <td colspan="{{ $cols }}" align="right" style="border: 1px solid #d0d5dd; background-color: #f9fafb; padding: 4px 10px; font-size: 9.5px; color: #6b7280;">
            {{ count($rows) }} student{{ count($rows) === 1 ? '' : 's' }} in this marksheet.
        </td>
    </tr>
</table>
{{-- PDF-only "Page X of Y" footer — must sit after all content; see
     teacher-wise-evaluation.blade.php for why page_script() is used. --}}
@if ($isPdf)
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
@endif
</body>
</html>
