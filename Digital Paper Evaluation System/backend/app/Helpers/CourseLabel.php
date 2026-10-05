<?php

namespace App\Helpers;

use App\Models\Course;

/**
 * One way of writing a course everywhere text is built server-side (emails,
 * in-app notifications, Excel/PDF exports) — "Physics (PHY101) – Theory".
 * Courses are unique by name + code + type, so the type is what tells two
 * same-name-and-code courses apart. A course with no type (created before
 * the column existed) reads as before: "Physics (PHY101)". The frontend
 * twin is utils/course.js.
 */
class CourseLabel
{
    public static function full(?string $name, ?string $code, ?string $type): string
    {
        $label = trim((string) $name);
        if ($code !== null && trim($code) !== '') {
            $label .= ' ('.trim($code).')';
        }

        return self::withType($label, $type);
    }

    public static function of(?Course $course, string $fallback = 'the course'): string
    {
        return $course ? self::full($course->name, $course->code, $course->type) : $fallback;
    }

    /** "Physics – Theory" — for places that show the code separately. */
    public static function withType(?string $text, ?string $type): string
    {
        $text = trim((string) $text);

        return $type !== null && trim($type) !== '' ? "{$text} – ".trim($type) : $text;
    }
}
