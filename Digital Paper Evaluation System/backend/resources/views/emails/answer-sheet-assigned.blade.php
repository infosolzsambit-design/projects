<!DOCTYPE html>
{{--
    Plain, minimal format per the user's own reference screenshots
    (not the previous branded gradient-card design) — reused for both a
    fresh assignment (TeacherAssignmentMailService) and a reassignment
    (TeacherReassignMailService), which is why the intro line is the
    admin-editable $bodyText (already reads "assigned"/"reassigned"
    appropriately — see SendAssignmentEmailModal.vue's own default text)
    rather than a hardcoded sentence here.
--}}
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $emailSubject ?? 'Answer Sheets Assigned' }}</title>
</head>
<body style="margin:0; padding:24px; background-color:#ffffff; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:1.6; color:#1f2937;">
    <p style="margin:0 0 14px;">Dear {{ $teacherName }},</p>

    <p style="margin:0 0 14px; white-space:pre-line;">{{ $bodyText }}</p>

    <p style="margin:0 0 6px;"><strong>Assignment Details:</strong></p>
    <ul style="margin:0 0 14px; padding-left:22px;">
        <li><strong>Course:</strong> {{ $courseName }}</li>
        <li><strong>Number of Sheets Assigned:</strong> {{ $sheetsAssigned }}</li>
        <li><strong>Evaluation Start Date:</strong> {{ $evaluationStartDate }}</li>
        <li><strong>Evaluation End Date:</strong> {{ $evaluationEndDate }}</li>
        @if ($evaluationTimePerSheet)
            <li><strong>Evaluation time per sheet in minutes:</strong> {{ $evaluationTimePerSheet }}</li>
        @endif
    </ul>

    <p style="margin:0 0 14px;">Kindly log in to the <strong>{{ $siteTitle }}</strong> portal to review and complete the evaluation of these assigned answer sheets before the specified evaluation window closes.</p>

    <p style="margin:0;">
        Best regards,<br>
        <strong>System Administrator</strong><br>
        {{ $siteTitle }} Support Team
    </p>
</body>
</html>
