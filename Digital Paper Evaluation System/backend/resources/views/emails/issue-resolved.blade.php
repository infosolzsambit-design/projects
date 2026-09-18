<!DOCTYPE html>
{{-- Plain, minimal format — see answer-sheet-assigned.blade.php's own
     comment for why (matches the user's reference screenshots). Two
     genuinely different bullet lists depending on $isPrintingIssue: a
     printing issue is identified by the script's own Barcode No (there's
     no evaluation-window change to report — resolving it just swaps the
     PDF); a timing issue has no barcode line at all but does report the
     updated evaluation window and any admin remarks. --}}
<html lang="en">
<head>
<meta charset="utf-8">
<title>Issue Resolved — {{ $issueTypeName }}</title>
</head>
<body style="margin:0; padding:24px; background-color:#ffffff; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:1.6; color:#1f2937;">
    <p style="margin:0 0 14px;">Dear {{ $teacherName }},</p>

    <p style="margin:0 0 14px;">This is to inform you that the <strong>{{ $issueTypeName }}</strong> reported for the course {{ $courseName }} has been successfully resolved as of <strong>{{ $resolvedAt }}</strong>.</p>

    @if ($isPrintingIssue)
        <p style="margin:0 0 6px;"><strong>Ticket Details:</strong></p>
        <ul style="margin:0 0 14px; padding-left:22px;">
            <li><strong>Issue Type:</strong> {{ $issueTypeName }}</li>
            <li><strong>Course:</strong> {{ $courseName }}</li>
            <li><strong>Barcode No:</strong> {{ $barcode ?? '—' }}</li>
            <li><strong>Resolution Timestamp:</strong> {{ $resolvedAt }}</li>
            @if ($evaluationTimePerSheet)
                <li><strong>Evaluation time per sheet in minutes:</strong> {{ $evaluationTimePerSheet }}</li>
            @endif
        </ul>

        <p style="margin:0 0 14px;">You may now resume evaluating this answer sheet. Please log in to the <strong>{{ $siteTitle }}</strong> portal to proceed with the evaluation process.</p>
    @else
        <p style="margin:0 0 6px;"><strong>Issue Details &amp; Resolution Summary:</strong></p>
        <ul style="margin:0 0 14px; padding-left:22px;">
            <li><strong>Issue Type:</strong> {{ $issueTypeName }}</li>
            <li><strong>Course:</strong> {{ $courseName }}</li>
            <li><strong>Resolved At:</strong> {{ $resolvedAt }}</li>
            @if ($newEvaluationStartDate || $newEvaluationEndDate)
                <li><strong>Updated Evaluation Window:</strong> {{ $newEvaluationStartDate ?? '—' }} to {{ $newEvaluationEndDate ?? '—' }}</li>
            @endif
            @if ($evaluationTimePerSheet)
                <li><strong>Evaluation time per sheet in minutes:</strong> {{ $evaluationTimePerSheet }}</li>
            @endif
            @if ($adminRemarks)
                <li><strong>Admin Remarks:</strong> {{ $adminRemarks }}</li>
            @endif
        </ul>

        <p style="margin:0 0 14px;">You may now resume evaluating this answer sheet within the updated timeframe. Please log in to the <strong>{{ $siteTitle }}</strong> portal to proceed.</p>
    @endif

    <p style="margin:0;">
        Best regards,<br>
        <strong>System Administrator</strong><br>
        {{ $siteTitle }} Support Team
    </p>
</body>
</html>
