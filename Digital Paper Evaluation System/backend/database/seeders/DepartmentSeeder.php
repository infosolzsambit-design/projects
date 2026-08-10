<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

/**
 * Dummy data for teacher departments — the academic department a teacher
 * belongs to (e.g. "Computer Science Engineering"), covering Engineering,
 * Science, Arts, and Commerce streams.
 */
class DepartmentSeeder extends Seeder
{
    /**
     * @var list<array{name: string, code: string, short_description: string}>
     */
    public const DEPARTMENTS = [
        ['name' => 'Computer Science Engineering', 'code' => 'CSE', 'short_description' => 'Computer systems, programming, and software development.'],
        ['name' => 'Civil Engineering', 'code' => 'CE', 'short_description' => 'Design and construction of infrastructure such as buildings, roads, and bridges.'],
        ['name' => 'Electrical Engineering', 'code' => 'EE', 'short_description' => 'Electrical systems, power generation, and electronics fundamentals.'],
        ['name' => 'Mechanical Engineering', 'code' => 'ME', 'short_description' => 'Machinery, thermodynamics, and mechanical systems design.'],
        ['name' => 'Electronics & Communication Engineering', 'code' => 'ECE', 'short_description' => 'Electronic devices, circuits, and communication systems.'],
        ['name' => 'Physics', 'code' => 'PHY', 'short_description' => 'Fundamental principles of matter, energy, and the physical universe.'],
        ['name' => 'Chemistry', 'code' => 'CHEM', 'short_description' => 'Study of substances, their properties, and chemical reactions.'],
        ['name' => 'Mathematics', 'code' => 'MATH', 'short_description' => 'Mathematical theory, statistics, and applied problem-solving.'],
        ['name' => 'English', 'code' => 'ENG', 'short_description' => 'Language, literature, and communication skills.'],
        ['name' => 'Commerce', 'code' => 'COM', 'short_description' => 'Accounting, finance, business studies, and economics.'],
        ['name' => 'Management Studies', 'code' => 'MGT', 'short_description' => 'Business administration, leadership, and organizational management.'],
        ['name' => 'Humanities & Social Sciences', 'code' => 'HSS', 'short_description' => 'History, political science, sociology, and related disciplines.'],
        ['name' => 'Computer Applications', 'code' => 'CA', 'short_description' => 'Computer applications, software development, and IT services.'],
    ];

    public function run(): void
    {
        foreach (self::DEPARTMENTS as $department) {
            Department::withTrashed()->firstOrCreate(
                ['name' => $department['name']],
                ['code' => $department['code'], 'short_description' => $department['short_description'], 'status' => true],
            );
        }
    }
}
