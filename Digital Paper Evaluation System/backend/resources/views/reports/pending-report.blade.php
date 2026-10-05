<!DOCTYPE html>
{{--
    PendingReportController::export() — one row per teacher with sheets
    still pending, for BOTH downloads: served as-is for Excel (an HTML
    <table> Excel opens natively) and through dompdf for the PDF. Same
    one-table, inline-styles-only layout as teacher-wise-evaluation.blade.php,
    for the same reasons (Excel's HTML import only honours inline styles,
    and separate tables don't line up); @page is only read by dompdf.
--}}
@php
    $cols = 12;
    $th = 'border: 1px solid #1f3a82; background-color: #2f56c0; color: #ffffff; padding: 4px 6px; font-size: 9px;';
    $totals = [
        'allotted' => array_sum(array_column($rows, 'allotted')),
        'evaluated' => array_sum(array_column($rows, 'evaluated')),
        'pending' => array_sum(array_column($rows, 'pending')),
        'problem' => array_sum(array_column($rows, 'problem')),
    ];
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
            PENDING REPORT
        </td>
    </tr>
    <tr>
        <td colspan="{{ $cols }}" align="center" style="border: 1px solid #1f3a82; background-color: #eef2fb; padding: 5px 10px; font-size: 10px; font-weight: bold; color: #1f3a82; letter-spacing: 0.03em;">
            Examination: {{ $examinationName ?? '—' }}&nbsp;&nbsp;|&nbsp;&nbsp;Course: {{ $courseLabel ?? '—' }}
        </td>
    </tr>
    <tr>
        <td colspan="6" align="left" style="border: 1px solid #dae2f0; background-color: #f0f3f8; padding: 4px 10px; font-size: 9px; color: #374151;">
            <strong>Printed By:</strong> {{ $printedBy }}
        </td>
        <td colspan="6" align="right" style="border: 1px solid #dae2f0; background-color: #f0f3f8; padding: 4px 10px; font-size: 9px; color: #374151;">
            <strong>Download Time:</strong> {{ $downloadedAt }}
        </td>
    </tr>

    <tr>
        <th align="center" style="{{ $th }}">Sl. No.</th>
        <th align="center" style="{{ $th }}">Teacher Name</th>
        <th align="center" style="{{ $th }}">Emp Code</th>
        <th align="center" style="{{ $th }}">Mobile No</th>
        <th align="center" style="{{ $th }}">Email</th>
        <th align="center" style="{{ $th }}">Department</th>
        <th align="center" style="{{ $th }}">Allotted Script</th>
        <th align="center" style="{{ $th }}">Evaluated</th>
        <th align="center" style="{{ $th }}">Pending</th>
        <th align="center" style="{{ $th }}">Problem</th>
        <th align="center" style="{{ $th }}">Evaluation Start Date</th>
        <th align="center" style="{{ $th }}">Evaluation End Date</th>
    </tr>

    @forelse ($rows as $i => $row)
        @php
            $bg = $i % 2 === 1 ? '#f6f8fc' : '#ffffff';
            $td = "border: 1px solid #d0d5dd; background-color: {$bg}; padding: 3px 6px; font-size: 8.5px;";
            $text = "mso-number-format:'\@';";
        @endphp
        <tr>
            <td align="center" style="{{ $td }}">{{ $i + 1 }}</td>
            <td align="left" style="{{ $td }} font-weight: bold;">{{ $row['teacher_name'] }}</td>
            <td align="center" style="{{ $td }}">{{ $row['emp_code'] ?? '—' }}</td>
            <td align="center" style="{{ $td }} {{ $text }}">{{ $row['mobile_no'] ?? '—' }}</td>
            <td align="left" style="{{ $td }}">{{ $row['email'] ?? '—' }}</td>
            <td align="left" style="{{ $td }}">{{ $row['department_names'] ? implode(', ', $row['department_names']) : '—' }}</td>
            <td align="center" style="{{ $td }}">{{ $row['allotted'] }}</td>
            <td align="center" style="{{ $td }} color: #16a34a; font-weight: bold;">{{ $row['evaluated'] }}</td>
            <td align="center" style="{{ $td }} color: #e81b26; font-weight: bold;">{{ $row['pending'] }}</td>
            <td align="center" style="{{ $td }} color: #d97706;">{{ $row['problem'] }}</td>
            <td align="center" style="{{ $td }}">{{ $row['evaluation_start_date'] ?? '—' }}</td>
            <td align="center" style="{{ $td }}{{ $row['overdue'] ? ' color: #e81b26; font-weight: bold;' : '' }}">{{ $row['evaluation_end_date'] ?? '—' }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="{{ $cols }}" align="center" style="border: 1px solid #d0d5dd; padding: 10px; font-size: 11px;">No teacher has pending answer sheets for this search.</td>
        </tr>
    @endforelse

    @if (count($rows))
        @php $tot = 'border: 1px solid #1f3a82; background-color: #eef2fb; padding: 4px 6px; font-size: 9px; font-weight: bold; color: #1f3a82;'; @endphp
        <tr>
            <td colspan="6" align="right" style="{{ $tot }}">Total</td>
            <td align="center" style="{{ $tot }}">{{ $totals['allotted'] }}</td>
            <td align="center" style="{{ $tot }}">{{ $totals['evaluated'] }}</td>
            <td align="center" style="{{ $tot }}">{{ $totals['pending'] }}</td>
            <td align="center" style="{{ $tot }}">{{ $totals['problem'] }}</td>
            <td colspan="2" style="{{ $tot }}"></td>
        </tr>
    @endif

    <tr>
        <td colspan="{{ $cols }}" align="right" style="border: 1px solid #d0d5dd; background-color: #f9fafb; padding: 4px 10px; font-size: 9.5px; color: #6b7280;">
            {{ count($rows) }} teacher{{ count($rows) === 1 ? '' : 's' }} with pending answer sheets.
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
