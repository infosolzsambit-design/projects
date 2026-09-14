<?php

namespace Database\Seeders;

use App\Models\Student;
use Illuminate\Database\Seeder;

/**
 * Dummy student data, spread across the programs seeded by ProgramSeeder.
 * Names are not unique on purpose — see StudentManagementTest.
 */
class StudentSeeder extends Seeder
{
    /**
     * @var list<array{name: string, semester: int, program_name: string}>
     */
    public const STUDENTS = [
        ['name' => 'Aarav Sharma', 'semester' => 1, 'program_name' => 'B.Tech in Computer Science Engineering'],
        ['name' => 'Vivaan Gupta', 'semester' => 3, 'program_name' => 'B.Tech in Computer Science Engineering'],
        ['name' => 'Aditya Singh', 'semester' => 5, 'program_name' => 'B.Tech in Civil Engineering'],
        ['name' => 'Vihaan Kumar', 'semester' => 1, 'program_name' => 'B.Tech in Electrical Engineering'],
        ['name' => 'Arjun Verma', 'semester' => 7, 'program_name' => 'B.Tech in Mechanical Engineering'],
        ['name' => 'Sai Reddy', 'semester' => 3, 'program_name' => 'B.Tech in Electronics & Communication Engineering'],
        ['name' => 'Reyansh Iyer', 'semester' => 2, 'program_name' => 'Diploma in Computer Science Engineering'],
        ['name' => 'Ayaan Khan', 'semester' => 4, 'program_name' => 'Diploma in Civil Engineering'],
        ['name' => 'Ishaan Joshi', 'semester' => 6, 'program_name' => 'B.Sc in Physics'],
        ['name' => 'Kabir Nair', 'semester' => 2, 'program_name' => 'B.Sc in Chemistry'],
        ['name' => 'Ananya Patel', 'semester' => 1, 'program_name' => 'B.Sc in Mathematics'],
        ['name' => 'Diya Mehta', 'semester' => 5, 'program_name' => 'B.A in English'],
        ['name' => 'Saanvi Rao', 'semester' => 3, 'program_name' => 'B.A in History'],
        ['name' => 'Myra Choudhury', 'semester' => 1, 'program_name' => 'B.Com in Accounting & Finance'],
        ['name' => 'Aadhya Bose', 'semester' => 2, 'program_name' => 'Bachelor of Computer Applications'],
        ['name' => 'Kiara Das', 'semester' => 4, 'program_name' => 'Bachelor of Computer Applications'],
        ['name' => 'Riya Banerjee', 'semester' => 1, 'program_name' => 'Master of Computer Applications'],
        ['name' => 'Aarav Sharma', 'semester' => 1, 'program_name' => 'Master of Business Administration'],
        ['name' => 'Advait Malhotra', 'semester' => 3, 'program_name' => 'M.Tech in Computer Science Engineering'],
        ['name' => 'Pihu Kapoor', 'semester' => 2, 'program_name' => 'Diploma in Electrical Engineering'],
    ];

    public function run(): void
    {
        // Student names aren't unique (see StudentManagementTest), so
        // there's no natural key for an idempotent firstOrCreate like the
        // other seeders use — guard against duplicating this dummy data if
        // the seeder gets run more than once instead.
        if (Student::withTrashed()->exists()) {
            return;
        }

        foreach (self::STUDENTS as $student) {
            Student::create([
                'name' => $student['name'],
                'semester' => $student['semester'],
                'program_name' => $student['program_name'],
                'status' => true,
            ]);
        }
    }
}
