{{--
    First row of every table-style report (Excel + PDF): the report logo
    top-left and General Settings' Organization Logo top-centre, on white
    so both read clearly. Splits the report's $cols columns into three
    colspans (left / centre / right) so it lines up with the table below.
    Inline styles only — see teacher-wise-evaluation.blade.php for why.
--}}
@php
    $side = intdiv($cols, 3);
    $middle = $cols - 2 * $side;
    // Outer frame only — no lines between the three cells.
    $cell = 'background-color: #ffffff; border-top: 1px solid #1f3a82; border-bottom: 1px solid #1f3a82; padding: 6px 10px; height: 46px; vertical-align: middle;';
@endphp
<tr>
    <td colspan="{{ $side }}" align="left" style="{{ $cell }} border-left: 1px solid #1f3a82; border-right: 0;">
        @if ($logoDataUri)
            <img src="{{ $logoDataUri }}" height="34" style="vertical-align: middle;" alt="" />
        @endif
    </td>
    <td colspan="{{ $middle }}" align="center" style="{{ $cell }} border-left: 0; border-right: 0;">
        @if ($organizationLogoDataUri ?? null)
            <img src="{{ $organizationLogoDataUri }}" height="38" style="vertical-align: middle;" alt="" />
        @endif
    </td>
    <td colspan="{{ $side }}" style="{{ $cell }} border-right: 1px solid #1f3a82; border-left: 0;"></td>
</tr>
