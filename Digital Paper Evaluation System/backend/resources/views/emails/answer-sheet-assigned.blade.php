<!DOCTYPE html>
{{--
    Same content as before (per the user's own reference screenshots) —
    reused for both a fresh assignment (TeacherAssignmentMailService) and a
    reassignment (TeacherReassignMailService), which is why the intro line is
    the admin-editable $bodyText (already reads "assigned"/"reassigned"
    appropriately — see SendAssignmentEmailModal.vue's own default text)
    rather than a hardcoded sentence here. Only the wrapping design below is
    new — a themed card matching the app's own brand-blue/subject-header
    look (see frontend/tailwind.config.js), not a content change.
--}}
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $emailSubject ?? 'Answer Sheets Assigned' }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f0f3f8; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:1.6; color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f3f8;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:580px; background-color:#ffffff; border-radius:12px; overflow:hidden;">
                    @include('emails.partials.header')
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 14px;">Dear {{ $teacherName }},</p>

                            <p style="margin:0 0 14px; white-space:pre-line;">{{ $bodyText }}</p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f3f8; border-left:4px solid #2f56c0; border-radius:6px; margin:0 0 16px;">
                                <tr>
                                    <td style="padding:14px 18px;">
                                        <p style="margin:0 0 8px; font-weight:bold;">Assignment Details:</p>
                                        <ul style="margin:0; padding-left:20px;">
                                            <li><strong>Course:</strong> {{ $courseName }}</li>
                                            @if ($poolTeacherCount ?? null)
                                                <li><strong>Shared Pool:</strong> {{ $sheetsAssigned }} answer sheets, shared by {{ $poolTeacherCount }} teachers — the first teacher to start a sheet gets it</li>
                                            @else
                                                <li><strong>Number of Sheets Assigned:</strong> {{ $sheetsAssigned }}</li>
                                            @endif
                                            <li><strong>Evaluation Start Date:</strong> {{ $evaluationStartDate }}</li>
                                            <li><strong>Evaluation End Date:</strong> {{ $evaluationEndDate }}</li>
                                            @if ($evaluationTimePerSheet)
                                                <li><strong>Evaluation time per sheet in minutes:</strong> {{ $evaluationTimePerSheet }}</li>
                                            @endif
                                        </ul>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 20px;">Kindly log in to the <strong>{{ $siteTitle }}</strong> portal to review and complete the evaluation of these assigned answer sheets before the specified evaluation window closes.</p>

                            <p style="margin:0; padding-top:16px; border-top:1px solid #eef0f4;">
                                Best regards,<br>
                                <strong>System Administrator</strong><br>
                                {{ $siteTitle }} Support Team
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
