<?php

namespace App\Http\Requests\Qr;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /qr/packets/{mapping}/scan — one scanned answer sheet: its PDF and
 * the QR code number the browser read off its first page (utils/qr.js, the
 * same reader Answer Sheet Upload uses — there's no PHP QR reader here).
 */
class ScanAnswerSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qr_code' => ['required', 'string', 'max:255'],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:51200'],
        ];
    }

    public function messages(): array
    {
        return [
            'qr_code.required' => 'No QR code was read from this scan.',
            'pdf.mimes' => 'The scan must be a PDF.',
        ];
    }
}
