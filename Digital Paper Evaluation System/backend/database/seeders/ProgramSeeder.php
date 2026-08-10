<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Program;
use Illuminate\Database\Seeder;

/**
 * "Program" in this project means a degree/diploma program (e.g. B.Tech in
 * Civil Engineering, Diploma in CSE) — as opposed to Course, which means an
 * individual subject (e.g. Physics). Dummy data covering Engineering,
 * Science, Arts, Commerce, and Computer Applications streams.
 *
 * Depends on CourseSeeder having run first — course_codes below are looked
 * up against courses.code to build the (now-mandatory) program-course
 * mapping.
 */
class ProgramSeeder extends Seeder
{
    /**
     * @var list<array{name: string, department: string, code: string, course_codes: list<string>}>
     */
    public const PROGRAMS = [
        ['name' => 'B.Tech in Civil Engineering', 'department' => 'Civil Engineering', 'code' => 'BTECH-CE', 'course_codes' => ['PHY101', 'MATH101']],
        ['name' => 'B.Tech in Computer Science Engineering', 'department' => 'Computer Science Engineering', 'code' => 'BTECH-CSE', 'course_codes' => ['CS101', 'MATH101', 'IT101']],
        ['name' => 'B.Tech in Electrical Engineering', 'department' => 'Electrical Engineering', 'code' => 'BTECH-EE', 'course_codes' => ['PHY101', 'MATH101', 'ELEC101']],
        ['name' => 'B.Tech in Mechanical Engineering', 'department' => 'Mechanical Engineering', 'code' => 'BTECH-ME', 'course_codes' => ['PHY101', 'MATH101']],
        ['name' => 'B.Tech in Electronics & Communication Engineering', 'department' => 'Electronics & Communication Engineering', 'code' => 'BTECH-ECE', 'course_codes' => ['PHY101', 'ELEC101', 'MATH101']],
        ['name' => 'Diploma in Computer Science Engineering', 'department' => 'Computer Science Engineering', 'code' => 'DIP-CSE', 'course_codes' => ['CS101', 'IT101']],
        ['name' => 'Diploma in Civil Engineering', 'department' => 'Civil Engineering', 'code' => 'DIP-CE', 'course_codes' => ['PHY101', 'MATH101']],
        ['name' => 'Diploma in Mechanical Engineering', 'department' => 'Mechanical Engineering', 'code' => 'DIP-ME', 'course_codes' => ['PHY101', 'MATH101']],
        ['name' => 'Diploma in Electrical Engineering', 'department' => 'Electrical Engineering', 'code' => 'DIP-EE', 'course_codes' => ['PHY101', 'ELEC101']],
        ['name' => 'B.Sc in Physics', 'department' => 'Science', 'code' => 'BSC-PHY', 'course_codes' => ['PHY101', 'MATH101']],
        ['name' => 'B.Sc in Chemistry', 'department' => 'Science', 'code' => 'BSC-CHEM', 'course_codes' => ['CHEM101', 'MATH101']],
        ['name' => 'B.Sc in Mathematics', 'department' => 'Science', 'code' => 'BSC-MATH', 'course_codes' => ['MATH101', 'STAT101']],
        ['name' => 'B.A in English', 'department' => 'Arts', 'code' => 'BA-ENG', 'course_codes' => ['ENG101']],
        ['name' => 'B.A in History', 'department' => 'Arts', 'code' => 'BA-HIST', 'course_codes' => ['HIST101']],
        ['name' => 'B.Com in Accounting & Finance', 'department' => 'Commerce', 'code' => 'BCOM-AF', 'course_codes' => ['ACC101', 'ECO101', 'BST101']],
        ['name' => 'M.Tech in Computer Science Engineering', 'department' => 'Computer Science Engineering', 'code' => 'MTECH-CSE', 'course_codes' => ['CS101', 'IT101']],
        ['name' => 'Master of Business Administration', 'department' => 'Management', 'code' => 'MBA101', 'course_codes' => ['BST101', 'ECO101']],
        ['name' => 'Bachelor of Computer Applications', 'department' => 'Computer Applications', 'code' => 'BCA101', 'course_codes' => ['CS101', 'MATH101']],
        ['name' => 'Master of Computer Applications', 'department' => 'Computer Applications', 'code' => 'MCA101', 'course_codes' => ['CS101', 'IT101']],
    ];

    public function run(): void
    {
        foreach (self::PROGRAMS as $program) {
            $record = Program::withTrashed()->firstOrCreate(
                ['code' => $program['code']],
                ['name' => $program['name'], 'department' => $program['department'], 'status' => true],
            );

            $courseIds = Course::whereIn('code', $program['course_codes'])->pluck('id');
            $record->courses()->sync($courseIds);
        }
    }
}
