<?php

namespace Tests\Feature;

use App\Helpers\CourseLabel;
use App\Models\Course;
use Tests\TestCase;

class CourseLabelTest extends TestCase
{
    public function test_full_label_is_name_code_and_type(): void
    {
        $this->assertSame('Physics (PHY101) – Theory', CourseLabel::full('Physics', 'PHY101', 'Theory'));
    }

    public function test_a_course_without_a_type_reads_as_before(): void
    {
        $this->assertSame('Physics (PHY101)', CourseLabel::full('Physics', 'PHY101', null));
        $this->assertSame('Physics (PHY101)', CourseLabel::full('Physics', 'PHY101', ' '));
    }

    public function test_with_type_and_of(): void
    {
        $this->assertSame('Physics – P', CourseLabel::withType('Physics', 'P'));
        $this->assertSame('Physics', CourseLabel::withType('Physics', null));
        $this->assertSame('Chemistry (CHE101) – Practical', CourseLabel::of(new Course(['name' => 'Chemistry', 'code' => 'CHE101', 'type' => 'Practical'])));
        $this->assertSame('a course', CourseLabel::of(null, 'a course'));
    }
}
