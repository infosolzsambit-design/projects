<?php

namespace Tests\Feature;

use App\Helpers\ProgramLabel;
use App\Models\Program;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_appends_the_label_in_brackets(): void
    {
        Program::factory()->create(['name' => 'Bachelor of Science (B.Sc)', 'label' => 'UG']);

        $this->assertSame('Bachelor of Science (B.Sc) (UG)', ProgramLabel::display('Bachelor of Science (B.Sc)'));
    }

    public function test_a_name_under_several_labels_lists_them_all(): void
    {
        Program::factory()->create(['name' => 'B.Sc', 'code' => 'BSC', 'label' => 'UG']);
        Program::factory()->create(['name' => 'B.Sc', 'code' => 'BSC', 'label' => 'PG']);

        $this->assertSame('B.Sc (UG / PG)', ProgramLabel::display('B.Sc'));
    }

    public function test_unlabelled_unknown_and_empty_names_are_left_as_is(): void
    {
        Program::factory()->create(['name' => 'Old Program', 'label' => null]);

        $this->assertSame('Old Program', ProgramLabel::display('Old Program'));
        $this->assertSame('Not A Program', ProgramLabel::display('Not A Program'));
        $this->assertNull(ProgramLabel::display(null));
    }

    public function test_soft_deleted_programs_labels_are_ignored(): void
    {
        Program::factory()->create(['name' => 'M.Sc', 'label' => 'PG'])->delete();

        $this->assertSame('M.Sc', ProgramLabel::display('M.Sc'));
    }
}
