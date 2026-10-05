<!DOCTYPE html>
{{-- Same content as before — see answer-sheet-assigned.blade.php's own
     comment for why (matches the user's reference screenshots). Two
     genuinely different bullet lists depending on $isPrintingIssue: a
     printing issue is identified by the script's own Barcode No (there's
     no evaluation-window change to report — resolving it just swaps the
     PDF); a timing issue has no barcode line at all but does report the
     updated evaluation window and any admin remarks. Only the wrapping
     design below is new, matching the app's own brand-blue look. --}}
<html lang="en">
<head>
<meta charset="utf-8">
<title>Issue Resolved — {{ $issueTypeName }}</title>
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

                            <p style="margin:0 0 14px;">This is to inform you that the <strong>{{ $issueTypeName }}</strong> reported for the course {{ $courseName }} has been successfully resolved as of <strong>{{ $resolvedAt }}</strong>.</p>

                            @if ($isPrintingIssue)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f3f8; border-left:4px solid #2f56c0; border-radius:6px; margin:0 0 16px;">
                                    <tr>
                                        <td style="padding:14px 18px;">
                                            <p style="margin:0 0 8px; font-weight:bold;">Ticket Details:</p>
                                            <ul style="margin:0; padding-left:20px;">
                                                <li><strong>Issue Type:</strong> {{ $issueTypeName }}</li>
                                                <li><strong>Course:</strong> {{ $courseName }}</li>
                                                <li><strong>Barcode No:</strong> {{ $barcode ?? '—' }}</li>
                                                <li><strong>Resolution Timestamp:</strong> {{ $resolvedAt }}</li>
                                                @if ($evaluationTimePerSheet)
                                                    <li><strong>Evaluation time per sheet in minutes:</strong> {{ $evaluationTimePerSheet }}</li>
                                                @endif
                                            </ul>
                                        </td>
                                    </tr>
                                </table>

                                <p style="margin:0 0 20px;">You may now resume evaluating this answer sheet. Please log in to the <strong>{{ $siteTitle }}</strong> portal to proceed with the evaluation process.</p>
                            @else
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f3f8; border-left:4px solid #2f56c0; border-radius:6px; margin:0 0 16px;">
                                    <tr>
                                        <td style="padding:14px 18px;">
                                            <p style="margin:0 0 8px; font-weight:bold;">Issue Details &amp; Resolution Summary:</p>
                                            <ul style="margin:0; padding-left:20px;">
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
                                        </td>
                                    </tr>
                                </table>

                                <p style="margin:0 0 20px;">You may now resume evaluating this answer sheet within the updated timeframe. Please log in to the <strong>{{ $siteTitle }}</strong> portal to proceed.</p>
                            @endif

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
