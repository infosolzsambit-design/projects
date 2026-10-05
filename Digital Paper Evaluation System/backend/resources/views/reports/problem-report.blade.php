<!DOCTYPE html>
{{--
    ProblemReportController::export() — Excel download only (a plain HTML
    <table> served with an Excel content-type, opened natively by Excel).
    Same one-table, inline-styles-only layout as teacher-wise-evaluation.
    blade.php, for the same reason: Excel's HTML import only reliably
    honours inline styles, and separate tables don't line up.
--}}
@php
    $cols = 17;
    $th = 'border: 1px solid #1f3a82; background-color: #2f56c0; color: #ffffff; padding: 4px 6px; font-size: 9px;';
@endphp
<html>
<head>
<meta charset="utf-8">
</head>
<body style="margin: 0; font-family: Arial, Helvetica, sans-serif; color: #1f2937;">
<table border="1" cellspacing="0" cellpadding="4" style="width: 100%; border-collapse: collapse; border: 1px solid #1f3a82;">
    @include('reports.partials.logo-header', ['cols' => $cols])
    <tr>
        <td colspan="{{ $cols }}" align="center" style="border: 1px solid #1f3a82; background-color: #24418f; padding: 5px 10px 7px; font-size: 10px; font-weight: bold; color: #ffffff; letter-spacing: 0.1em;">
            PROBLEM REPORT
        </td>
    </tr>
    <tr>
        <td colspan="{{ $cols }}" align="center" style="border: 1px solid #1f3a82; background-color: #eef2fb; padding: 5px 10px; font-size: 10px; font-weight: bold; color: #1f3a82; letter-spacing: 0.03em;">
            Examination: {{ $examinationName ?? '—' }}
        </td>
    </tr>
    <tr>
        <td colspan="8" align="left" style="border: 1px solid #dae2f0; background-color: #f0f3f8; padding: 4px 10px; font-size: 9px; color: #374151;">
            <strong>Printed By:</strong> {{ $printedBy }}
        </td>
        <td colspan="9" align="right" style="border: 1px solid #dae2f0; background-color: #f0f3f8; padding: 4px 10px; font-size: 9px; color: #374151;">
            <strong>Download Time:</strong> {{ $downloadedAt }}
        </td>
    </tr>

    <tr>
        <th align="center" style="{{ $th }}">Teacher Name</th>
        <th align="center" style="{{ $th }}">Emp Code</th>
        <th align="center" style="{{ $th }}">Program</th>
        <th align="center" style="{{ $th }}">Course</th>
        <th align="center" style="{{ $th }}">Course Code</th>
        <th align="center" style="{{ $th }}">Semester</th>
        <th align="center" style="{{ $th }}">Exam Term</th>
        <th align="center" style="{{ $th }}">Examination</th>
        <th align="center" style="{{ $th }}">Exam Year</th>
        <th align="center" style="{{ $th }}">Paper QR Code</th>
        <th align="center" style="{{ $th }}">Issue Type</th>
        <th align="center" style="{{ $th }}">Status</th>
        <th align="center" style="{{ $th }}">Teacher Remarks</th>
        <th align="center" style="{{ $th }}">Issue Time</th>
        <th align="center" style="{{ $th }}">Solved By</th>
        <th align="center" style="{{ $th }}">Solved Date</th>
        <th align="center" style="{{ $th }}">Admin Remarks</th>
    </tr>

    @forelse ($rows as $i => $row)
        @php
            $bg = $i % 2 === 1 ? '#f6f8fc' : '#ffffff';
            $td = "border: 1px solid #d0d5dd; background-color: {$bg}; padding: 3px 6px; font-size: 8.5px;";
        @endphp
        <tr>
            <td align="left" style="{{ $td }} font-weight: bold;">{{ $row['teacher_name'] ?? '—' }}</td>
            <td align="center" style="{{ $td }}">{{ $row['emp_code'] ?? '—' }}</td>
            <td align="left" style="{{ $td }}">{{ \App\Helpers\ProgramLabel::display($row['program_name'] ?? null) ?? '—' }}</td>
            <td align="left" style="{{ $td }}">{{ \App\Helpers\CourseLabel::withType($row['course_name'] ?? '—', $row['course_type'] ?? null) }}</td>
            <td align="center" style="{{ $td }}">{{ $row['course_code'] ?? '—' }}</td>
            <td align="center" style="{{ $td }}">{{ $row['semester'] }}</td>
            <td align="center" style="{{ $td }}">{{ $row['exam_term_name'] ?? '—' }}</td>
            <td align="center" style="{{ $td }}">{{ $row['exam_type_name'] ?? '—' }}</td>
            <td align="center" style="{{ $td }}">{{ $row['exam_year'] }}</td>
            {{-- Forced to text so Excel doesn't show a long numeric code in
                 scientific notation or drop leading zeros. --}}
            <td align="center" style="{{ $td }} mso-number-format:'\@';">{{ $row['qr_code'] ?? '—' }}</td>
            <td align="center" style="{{ $td }}">{{ $row['issue_type'] ?? '—' }}</td>
            <td align="center" style="{{ $td }} font-weight: bold; color: {{ $row['status'] === 'Resolved' ? '#16a34a' : '#e81b26' }};">{{ $row['status'] }}</td>
            <td align="left" style="{{ $td }}">{{ $row['teacher_remarks'] ?? '—' }}</td>
            <td align="center" style="{{ $td }}">{{ $row['issue_raised_at'] ?? '—' }}</td>
            <td align="center" style="{{ $td }}">{{ $row['solved_by'] ?? '—' }}</td>
            <td align="center" style="{{ $td }}">{{ $row['solved_at'] ?? '—' }}</td>
            <td align="left" style="{{ $td }}">{{ $row['admin_remarks'] ?? '—' }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="{{ $cols }}" align="center" style="border: 1px solid #d0d5dd; padding: 10px; font-size: 11px;">No problems found for this search.</td>
        </tr>
    @endforelse

    <tr>
        <td colspan="{{ $cols }}" align="right" style="border: 1px solid #d0d5dd; background-color: #f9fafb; padding: 4px 10px; font-size: 9.5px; color: #6b7280;">
            {{ count($rows) }} record{{ count($rows) === 1 ? '' : 's' }} in this report.
        </td>
    </tr>
</table>
</body>
</html>
