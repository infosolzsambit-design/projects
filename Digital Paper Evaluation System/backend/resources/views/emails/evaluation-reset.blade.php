<!DOCTYPE html>
{{-- Same wrapping design as issue-resolved.blade.php (brand gradient
     header, details box, sign-off) — only the wording and the details
     list differ. Sent by EvaluationResetMailService. --}}
<html lang="en">
<head>
<meta charset="utf-8">
<title>Evaluation Reset — {{ $courseName }}</title>
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

                            <p style="margin:0 0 14px;">This is to inform you that the evaluation of an answer sheet in the course {{ $courseName }} has been <strong>reset</strong> by the administrator as of <strong>{{ $resetAt }}</strong>. The previous marks and annotations have been cleared, and the answer sheet is back in your pending list.</p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f3f8; border-left:4px solid #2f56c0; border-radius:6px; margin:0 0 16px;">
                                <tr>
                                    <td style="padding:14px 18px;">
                                        <p style="margin:0 0 8px; font-weight:bold;">Answer Sheet Details:</p>
                                        <ul style="margin:0; padding-left:20px;">
                                            @if ($programName)
                                                <li><strong>Program:</strong> {{ $programName }}</li>
                                            @endif
                                            <li><strong>Course:</strong> {{ $courseName }}</li>
                                            @if ($departmentName)
                                                <li><strong>Department:</strong> {{ $departmentName }}</li>
                                            @endif
                                            <li><strong>Barcode No:</strong> {{ $barcode ?? '—' }}</li>
                                            <li><strong>Reset By:</strong> {{ $resetByName }}</li>
                                            <li><strong>Reset At:</strong> {{ $resetAt }}</li>
                                            @if ($evaluationEndDate)
                                                <li><strong>Evaluate Before:</strong> {{ $evaluationEndDate }}</li>
                                            @endif
                                            @if ($evaluationTimePerSheet)
                                                <li><strong>Evaluation time per sheet in minutes:</strong> {{ $evaluationTimePerSheet }}</li>
                                            @endif
                                        </ul>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 20px;">Please evaluate this answer sheet again before the end of the evaluation window. Log in to the <strong>{{ $siteTitle }}</strong> portal to proceed.</p>

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
