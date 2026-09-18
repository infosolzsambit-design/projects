<!DOCTYPE html>
{{-- Plain, minimal format — see answer-sheet-assigned.blade.php's own
     comment for why (matches the user's reference screenshots). Barcode
     No stands in for Roll No specifically for a printing issue — that's
     the identifier that actually matters when the physical scan itself
     is what's wrong; a timing issue still shows the roll no. --}}
<html lang="en">
<head>
<meta charset="utf-8">
<title>Issue Raised — {{ $issueTypeName }}</title>
</head>
<body style="margin:0; padding:24px; background-color:#ffffff; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:1.6; color:#1f2937;">
    <p style="margin:0 0 14px;">Dear {{ $adminName }},</p>

    <p style="margin:0 0 14px;">This is to inform you that a <strong>{{ $issueTypeName }}</strong> has been raised by {{ $teacherName }}@if ($teacherEmpCode) ({{ $teacherEmpCode }})@endif for the course {{ $courseName }}.</p>

    <p style="margin:0 0 6px;"><strong>Issue Details:</strong></p>
    <ul style="margin:0 0 14px; padding-left:22px;">
        <li><strong>Issue Type:</strong> {{ $issueTypeName }}</li>
        <li><strong>Raised By:</strong> {{ $teacherName }}@if ($teacherEmpCode) ({{ $teacherEmpCode }})@endif</li>
        <li><strong>Course:</strong> {{ $courseName }}</li>
        @if ($isPrintingIssue)
            <li><strong>Barcode No:</strong> {{ $barcode ?? '—' }}</li>
        @else
            <li><strong>Roll No:</strong> {{ $rollNo }}</li>
        @endif
        <li><strong>Raised At:</strong> {{ $raisedAt }}</li>
        @if ($evaluationTimePerSheet)
            <li><strong>Evaluation time per sheet in minutes:</strong> {{ $evaluationTimePerSheet }}</li>
        @endif
        <li><strong>Remarks:</strong> {{ $remarks }}</li>
    </ul>

    <p style="margin:0 0 14px;">Please log in to the <strong>{{ $siteTitle }}</strong> portal to review and resolve this issue.</p>

    <p style="margin:0;">
        Best regards,<br>
        <strong>System Administrator</strong><br>
        {{ $siteTitle }} Support Team
    </p>
</body>
</html>
