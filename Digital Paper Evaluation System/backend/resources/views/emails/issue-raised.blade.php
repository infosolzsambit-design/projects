<!DOCTYPE html>
{{-- Same content as before — see answer-sheet-assigned.blade.php's own
     comment for why (matches the user's reference screenshots). Barcode
     No stands in for Roll No specifically for a printing issue — that's
     the identifier that actually matters when the physical scan itself
     is what's wrong; a timing issue still shows the roll no. Only the
     wrapping design below is new, matching the app's own brand-blue look. --}}
<html lang="en">
<head>
<meta charset="utf-8">
<title>Issue Raised — {{ $issueTypeName }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f0f3f8; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:1.6; color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f3f8;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:580px; background-color:#ffffff; border-radius:12px; overflow:hidden;">
                    @include('emails.partials.header')
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 14px;">Dear {{ $adminName }},</p>

                            <p style="margin:0 0 14px;">This is to inform you that a <strong>{{ $issueTypeName }}</strong> has been raised by {{ $teacherName }}@if ($teacherEmpCode) ({{ $teacherEmpCode }})@endif for the course {{ $courseName }}.</p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f3f8; border-left:4px solid #2f56c0; border-radius:6px; margin:0 0 16px;">
                                <tr>
                                    <td style="padding:14px 18px;">
                                        <p style="margin:0 0 8px; font-weight:bold;">Issue Details:</p>
                                        <ul style="margin:0; padding-left:20px;">
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
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 20px;">Please log in to the <strong>{{ $siteTitle }}</strong> portal to review and resolve this issue.</p>

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
