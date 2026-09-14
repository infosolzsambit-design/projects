<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="x-apple-disable-message-reformatting">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>Reset Your Password</title>
<!--[if mso]>
<noscript>
<xml>
<o:OfficeDocumentSettings>
<o:PixelsPerInch>96</o:PixelsPerInch>
</o:OfficeDocumentSettings>
</xml>
</noscript>
<![endif]-->
<style>
  @media only screen and (max-width: 600px) {
    .cjpc-container { width: 100% !important; }
    .cjpc-card { padding: 28px 22px !important; }
    .cjpc-btn a { padding: 13px 30px !important; }
  }
</style>
</head>
<body style="margin:0; padding:0; background-color:#F0F3F8; -webkit-text-size-adjust:100%; text-size-adjust:100%;">
<!-- Preheader (hidden preview text shown in inbox lists) -->
<div style="display:none; max-height:0; overflow:hidden; opacity:0; mso-hide:all;">
  We received a request to reset the password on your CJ Paper Check account. This link expires in {{ $expireMinutes }} minutes.
</div>
<div style="display:none; max-height:0; overflow:hidden;">&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F0F3F8;">
  <tr>
    <td align="center" style="padding:40px 16px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="cjpc-container" style="max-width:560px;">
        <!-- Brand accent bar -->
        <tr>
          <td style="height:6px; line-height:6px; font-size:0; background-color:#2F56C0; background-image:linear-gradient(90deg,#E81B26,#2F56C0); border-radius:20px 20px 0 0;">&nbsp;</td>
        </tr>

        <!-- Card -->
        <tr>
          <td class="cjpc-card" style="background-color:#ffffff; padding:40px 44px 34px; border-radius:0 0 20px 20px; box-shadow:0 18px 50px rgba(47,86,192,0.10);">

            <!-- Logo -->
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td align="center" style="padding-bottom:26px;">
                  <img src="{{ $logoUrl }}" width="64" alt="CJ Paper Check" style="display:block; height:auto; border:0; outline:none; text-decoration:none;">
                </td>
              </tr>
            </table>

            <h1 style="margin:0 0 18px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:21px; line-height:28px; font-weight:700; color:#17181A; text-align:center;">
              Reset Your Password
            </h1>

            <p style="margin:0 0 6px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:14px; line-height:22px; color:#3D4451;">
              Hello {{ $name }},
            </p>
            <p style="margin:0 0 30px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:14px; line-height:22px; color:#5B6475;">
              We received a request to reset the password for your <strong style="color:#3D4451;">CJ Paper Check</strong> account. Click the button below to choose a new one.
            </p>

            <!-- CTA button -->
            <table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:0 auto 30px;">
              <tr>
                <td class="cjpc-btn" align="center" style="border-radius:999px; background-color:#2F56C0; background-image:linear-gradient(100deg,#E81B26 -30%,#2F56C0 110%);">
                  <a href="{{ $url }}" target="_blank" style="display:inline-block; padding:15px 46px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:14px; font-weight:700; letter-spacing:0.06em; color:#ffffff; text-decoration:none; text-transform:uppercase; border-radius:999px;">
                    Reset Password
                  </a>
                </td>
              </tr>
            </table>

            <p style="margin:0 0 4px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:12px; line-height:18px; color:#A0A7B4; text-align:center;">
              Or copy and paste this link into your browser:
            </p>
            <p style="margin:0 0 30px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:12px; line-height:18px; color:#2F56C0; text-align:center; word-break:break-all;">
              <a href="{{ $url }}" style="color:#2F56C0; text-decoration:underline;">{{ $url }}</a>
            </p>

            <!-- Notices -->
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #EAF1FF;">
              <tr>
                <td style="padding-top:22px;">
                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                      <td width="22" valign="top" style="font-size:14px; line-height:20px; padding-right:8px;">⏱️</td>
                      <td style="font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:12.5px; line-height:20px; color:#5B6475;">
                        This link will expire in <strong>{{ $expireMinutes }} minutes</strong> for your security.
                      </td>
                    </tr>
                  </table>
                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;">
                    <tr>
                      <td width="22" valign="top" style="font-size:14px; line-height:20px; padding-right:8px;">🔒</td>
                      <td style="font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:12.5px; line-height:20px; color:#5B6475;">
                        If you didn't request a password reset, no action is needed — your password will remain unchanged.
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td align="center" style="padding:26px 12px 0;">
            <p style="margin:0 0 4px; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:12px; color:#7A8AA0;">
              CJ Paper Check &middot; Digital Paper Evaluation System
            </p>
            <p style="margin:0; font-family:'Segoe UI',Helvetica,Arial,sans-serif; font-size:11px; color:#A0A7B4;">
              This is an automated message — please don't reply to this email.
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
