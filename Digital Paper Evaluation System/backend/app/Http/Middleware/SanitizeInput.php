<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mews\Purifier\Facades\Purifier;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strips HTML from every string in the request body/query by default,
 * before it ever reaches validation or a controller — so no individual
 * FormRequest has to remember to guard against it (an earlier, per-field
 * attempt at this on just Course/Program was corrected to be global here
 * instead). A <script>/<img onerror=...>/etc. submitted through any text
 * field, on any current or future endpoint, never gets stored in the first
 * place.
 *
 * Vue already HTML-escapes everything it renders by default (verified: a
 * stored <script> shows as inert escaped text in the DOM, never executes)
 * — this is defense in depth, not a fix for a live exploit. It protects
 * future code that might render this data differently (a v-html added
 * later, an export, a different client) from inheriting a risk it doesn't
 * know about.
 */
class SanitizeInput
{
    /**
     * Never touched — sanitizing a password could silently alter what the
     * user actually typed (e.g. a legitimate ">" character in it),
     * corrupting their password without them knowing.
     *
     * @var list<string>
     */
    private const EXEMPT_FIELDS = [
        'password',
        'password_confirmation',
        'current_password',
    ];

    /**
     * Field names that should keep a safe HTML subset (the 'rich_text'
     * purifier profile, config/purifier.php) instead of having everything
     * stripped. Empty today because no rich-text field exists yet — when
     * the text editor is added, list its field name(s) here.
     *
     * @var list<string>
     */
    private const RICH_TEXT_FIELDS = [];

    public function handle(Request $request, Closure $next): Response
    {
        $input = $request->all();

        if ($input !== []) {
            $request->merge($this->sanitize($input));
        }

        return $next($request);
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    private function sanitize(array $input, ?string $parentKey = null): array
    {
        foreach ($input as $key => $value) {
            $fieldName = is_string($key) ? $key : $parentKey;

            if (is_array($value)) {
                $input[$key] = $this->sanitize($value, $fieldName);

                continue;
            }

            if (! is_string($value) || in_array($fieldName, self::EXEMPT_FIELDS, true)) {
                continue;
            }

            $profile = in_array($fieldName, self::RICH_TEXT_FIELDS, true) ? 'rich_text' : 'plain_text';
            $input[$key] = Purifier::clean($value, $profile);
        }

        return $input;
    }
}
