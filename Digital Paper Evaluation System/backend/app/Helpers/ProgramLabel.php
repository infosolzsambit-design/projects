<?php

namespace App\Helpers;

use App\Models\Program;

/**
 * Server-side twin of the frontend's stores/programs.js: turns a stored
 * program name (packets, students and report rows all keep the plain
 * name) into its display text "Name (Label)", e.g. "Bachelor of Science
 * (B.Sc) (UG)". A name that exists under more than one label shows all of
 * them — "Name (UG / PG)". Used by the Excel/PDF exports.
 */
class ProgramLabel
{
    public static function display(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return $name;
        }

        $labels = self::labels()[mb_strtolower($name)] ?? [];

        return $labels ? $name.' ('.implode(' / ', $labels).')' : $name;
    }

    /**
     * Lower-cased name => labels, loaded once per request (once() is also
     * reset between tests).
     *
     * @return array<string, list<string>>
     */
    private static function labels(): array
    {
        return once(function (): array {
            $labels = [];
            foreach (Program::query()->whereNotNull('label')->where('label', '!=', '')->orderBy('id')->get(['name', 'label']) as $program) {
                $key = mb_strtolower($program->name);
                if (! in_array($program->label, $labels[$key] ?? [], true)) {
                    $labels[$key][] = $program->label;
                }
            }

            return $labels;
        });
    }
}
