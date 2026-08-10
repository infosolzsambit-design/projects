<?php

namespace Database\Seeders;

use App\Models\Course;
use Illuminate\Database\Seeder;

/**
 * "Course" in this project means an individual subject (e.g. Physics),
 * not a degree program (that's the Program model) — dummy data for a
 * typical college's subject list, covering Science/Arts/Commerce streams.
 */
class CourseSeeder extends Seeder
{
    /**
     * @var list<array{name: string, code: string}>
     */
    public const COURSES = [
        ['name' => 'English', 'code' => 'ENG101'],
        ['name' => 'Hindi', 'code' => 'HIN101'],
        ['name' => 'Mathematics', 'code' => 'MATH101'],
        ['name' => 'Physics', 'code' => 'PHY101'],
        ['name' => 'Chemistry', 'code' => 'CHEM101'],
        ['name' => 'Biology', 'code' => 'BIO101'],
        ['name' => 'Computer Science', 'code' => 'CS101'],
        ['name' => 'Statistics', 'code' => 'STAT101'],
        ['name' => 'Environmental Science', 'code' => 'ENV101'],
        ['name' => 'Economics', 'code' => 'ECO101'],
        ['name' => 'Business Studies', 'code' => 'BST101'],
        ['name' => 'Accountancy', 'code' => 'ACC101'],
        ['name' => 'History', 'code' => 'HIST101'],
        ['name' => 'Geography', 'code' => 'GEO101'],
        ['name' => 'Political Science', 'code' => 'POL101'],
        ['name' => 'Sociology', 'code' => 'SOC101'],
        ['name' => 'Psychology', 'code' => 'PSY101'],
        ['name' => 'Philosophy', 'code' => 'PHIL101'],
        ['name' => 'Physical Education', 'code' => 'PE101'],
        ['name' => 'Sanskrit', 'code' => 'SANS101'],
        ['name' => 'Bengali', 'code' => 'BENG101'],
        ['name' => 'Information Technology', 'code' => 'IT101'],
        ['name' => 'Electronics', 'code' => 'ELEC101'],
        ['name' => 'Botany', 'code' => 'BOT101'],
        ['name' => 'Zoology', 'code' => 'ZOO101'],
        ['name' => 'Music', 'code' => 'MUS101'],
        ['name' => 'Fine Arts', 'code' => 'ART101'],
        ['name' => 'Journalism and Mass Communication', 'code' => 'JMC101'],
        ['name' => 'Anthropology', 'code' => 'ANTH101'],
    ];

    public function run(): void
    {
        foreach (self::COURSES as $course) {
            Course::withTrashed()->firstOrCreate(
                ['code' => $course['code']],
                ['name' => $course['name'], 'status' => true],
            );
        }
    }
}
