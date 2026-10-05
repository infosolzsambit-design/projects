{{--
    Shared email header row: CJ logo top-left, General Settings' Organization
    Logo centred, on white (the organization logo is dark text), with a thin
    brand-gradient stripe underneath. Logos come from
    MailBrandingService::emailHeader() as full URLs. Table-based, inline
    styles only, so it renders the same in every mail client.
--}}
@php $emailHeader = app(\App\Services\MailBrandingService::class)->emailHeader(); @endphp
<tr>
    <td style="background-color:#ffffff; padding:14px 20px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td width="25%" align="left" style="vertical-align:middle;">
                    @if ($emailHeader['logo_url'])
                        <img src="{{ $emailHeader['logo_url'] }}" alt="CJ Paper Check" height="30" style="display:block; height:30px; width:auto; max-width:120px; border:0;">
                    @endif
                </td>
                <td width="50%" align="center" style="vertical-align:middle;">
                    @if ($emailHeader['organization_logo_url'])
                        <img src="{{ $emailHeader['organization_logo_url'] }}" alt="Organization" width="250" style="display:block; width:250px; max-width:100%; height:auto; border:0; margin:0 auto;">
                    @endif
                </td>
                <td width="25%">&nbsp;</td>
            </tr>
        </table>
    </td>
</tr>
<tr>
    <td style="height:4px; line-height:4px; font-size:0; background-color:#2f56c0; background-image:linear-gradient(90deg, #e81b26 0%, #2f56c0 100%);">&nbsp;</td>
</tr>
