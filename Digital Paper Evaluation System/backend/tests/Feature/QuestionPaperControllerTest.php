<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\ExamTerm;
use App\Models\QuestionPaper;
use App\Models\QuestionPaperNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuestionPaperControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** A minimal valid structure tree — one group, one leaf question. */
    private function validGroupsJson(): string
    {
        return json_encode([
            ['label' => 'Group A', 'mode' => 'all', 'children' => [
                ['label' => '1', 'mode' => 'leaf', 'marks' => 2],
            ]],
        ]);
    }

    public function test_admin_can_create_a_question_paper_with_its_structure_in_one_request(): void
    {
        Storage::fake('public');
        $this->actingAdmin();
        $course = Course::factory()->create();

        $response = $this->withApiKey()->post('/api/v1/question-papers', [
            'exam_year' => 2025,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'full_marks' => 80,
            'time_allotted' => '3 hours',
            'pdf' => UploadedFile::fake()->create('question-paper.pdf', 500, 'application/pdf'),
            'groups' => $this->validGroupsJson(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.exam_year', 2025)
            ->assertJsonPath('data.semester', 5)
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.groups.0.label', 'Group A')
            ->assertJsonPath('data.groups.0.children.0.marks', 2);

        $paper = QuestionPaper::first();
        $this->assertStringStartsWith('/storage/question-papers/', $paper->pdf_path);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $paper->pdf_path));
        $this->assertSame(1, $paper->groups()->count());
    }

    public function test_creating_a_paper_with_an_invalid_structure_writes_nothing(): void
    {
        Storage::fake('public');
        $this->actingAdmin();
        $course = Course::factory()->create();

        $response = $this->withApiKey()->post('/api/v1/question-papers', [
            'exam_year' => 2025,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'pdf' => UploadedFile::fake()->create('question-paper.pdf', 500, 'application/pdf'),
            // a leaf with no marks — invalid
            'groups' => json_encode([
                ['label' => 'Group A', 'mode' => 'all', 'children' => [
                    ['label' => '1', 'mode' => 'leaf'],
                ]],
            ]),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('groups.0.children.0.marks');
        $this->assertSame(0, QuestionPaper::count());
        $this->assertSame(0, QuestionPaperNode::count());
        Storage::disk('public')->assertDirectoryEmpty('question-papers');
    }

    public function test_create_requires_a_pdf_file(): void
    {
        $this->actingAdmin();
        $course = Course::factory()->create();

        $response = $this->withApiKey()->postJson('/api/v1/question-papers', [
            'exam_year' => 2025,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'groups' => $this->validGroupsJson(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('pdf');
    }

    public function test_create_rejects_a_non_pdf_file(): void
    {
        Storage::fake('public');
        $this->actingAdmin();
        $course = Course::factory()->create();

        $response = $this->withApiKey()->post('/api/v1/question-papers', [
            'exam_year' => 2025,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'pdf' => UploadedFile::fake()->image('not-a-pdf.jpg'),
            'groups' => $this->validGroupsJson(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('pdf');
    }

    public function test_create_requires_an_existing_course(): void
    {
        Storage::fake('public');
        $this->actingAdmin();

        $response = $this->withApiKey()->post('/api/v1/question-papers', [
            'exam_year' => 2025,
            'course_id' => 999999,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'pdf' => UploadedFile::fake()->create('question-paper.pdf', 100, 'application/pdf'),
            'groups' => $this->validGroupsJson(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('course_id');
    }

    public function test_create_requires_a_structure(): void
    {
        Storage::fake('public');
        $this->actingAdmin();
        $course = Course::factory()->create();

        $response = $this->withApiKey()->post('/api/v1/question-papers', [
            'exam_year' => 2025,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'pdf' => UploadedFile::fake()->create('question-paper.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('groups');
    }

    public function test_index_lists_question_papers_with_course_info(): void
    {
        $this->actingAdmin();
        $course = Course::factory()->create(['name' => 'Financial Accounting']);
        QuestionPaper::factory()->for($course)->create();

        $response = $this->withApiKey()->getJson('/api/v1/question-papers');

        $response->assertOk();
        $this->assertSame('Financial Accounting', $response->json('data.items.0.course_name'));
    }

    public function test_index_filters_by_exam_year(): void
    {
        $this->actingAdmin();
        QuestionPaper::factory()->create(['exam_year' => 2024]);
        QuestionPaper::factory()->create(['exam_year' => 2025]);

        $response = $this->withApiKey()->getJson('/api/v1/question-papers?exam_year=2025');

        $response->assertOk();
        $years = collect($response->json('data.items'))->pluck('exam_year');
        $this->assertSame([2025], $years->unique()->values()->toArray());
    }

    public function test_show_returns_a_nested_structure_tree_at_any_depth(): void
    {
        $this->actingAdmin();
        $paper = QuestionPaper::factory()->create();

        // Group A (mode=all) -> leaf "1(i)"
        $groupA = QuestionPaperNode::create(['question_paper_id' => $paper->id, 'parent_id' => null, 'label' => 'Group A', 'mode' => 'all', 'sort_order' => 0]);
        QuestionPaperNode::create(['question_paper_id' => $paper->id, 'parent_id' => $groupA->id, 'label' => '1(i)', 'mode' => 'leaf', 'marks' => 2, 'sort_order' => 0]);

        // Group B (mode=all) -> "2" (mode=choose, 2 alternatives) -> "i" (mode=choose, 2 lettered alternatives) -> leaves "a", "b"
        $groupB = QuestionPaperNode::create(['question_paper_id' => $paper->id, 'parent_id' => null, 'label' => 'Group B', 'mode' => 'all', 'sort_order' => 1]);
        $question2 = QuestionPaperNode::create(['question_paper_id' => $paper->id, 'parent_id' => $groupB->id, 'label' => '2', 'mode' => 'all', 'sort_order' => 0]);
        $partI = QuestionPaperNode::create(['question_paper_id' => $paper->id, 'parent_id' => $question2->id, 'label' => 'i', 'mode' => 'choose', 'choose_count' => 1, 'sort_order' => 0]);
        QuestionPaperNode::create(['question_paper_id' => $paper->id, 'parent_id' => $partI->id, 'label' => 'a', 'mode' => 'leaf', 'marks' => 5, 'sort_order' => 0]);
        QuestionPaperNode::create(['question_paper_id' => $paper->id, 'parent_id' => $partI->id, 'label' => 'b', 'mode' => 'leaf', 'marks' => 5, 'sort_order' => 1]);

        $response = $this->withApiKey()->getJson("/api/v1/question-papers/{$paper->id}");

        $response->assertOk()
            ->assertJsonPath('data.groups.0.label', 'Group A')
            ->assertJsonPath('data.groups.0.children.0.label', '1(i)')
            ->assertJsonPath('data.groups.0.children.0.marks', 2)
            ->assertJsonPath('data.groups.1.children.0.label', '2')
            ->assertJsonPath('data.groups.1.children.0.children.0.label', 'i')
            ->assertJsonPath('data.groups.1.children.0.children.0.mode', 'choose')
            ->assertJsonPath('data.groups.1.children.0.children.0.children.0.label', 'a')
            ->assertJsonPath('data.groups.1.children.0.children.0.children.1.label', 'b');
    }

    public function test_update_saves_a_flat_choose_n_of_m_group(): void
    {
        $this->actingAdmin();
        $paper = QuestionPaper::factory()->create();
        $course = Course::factory()->create();

        $response = $this->withApiKey()->putJson("/api/v1/question-papers/{$paper->id}", [
            'exam_year' => 2025,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'full_marks' => 80,
            'time_allotted' => '3 hours',
            'groups' => [
                [
                    'label' => 'Group A',
                    'instruction' => 'Answer any 10 of the following 12 questions.',
                    'mode' => 'choose',
                    'choose_count' => 10,
                    'children' => array_map(
                        fn ($n) => ['label' => "1({$n})", 'mode' => 'leaf', 'marks' => 2],
                        ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x', 'xi', 'xii'],
                    ),
                ],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'ready');
        $paper->refresh();
        $this->assertSame('ready', $paper->status);
        $this->assertSame(80, $paper->full_marks);
        $groupA = $paper->groups()->first();
        $this->assertSame('choose', $groupA->mode);
        $this->assertSame(10, $groupA->choose_count);
        $this->assertSame(12, $groupA->children()->count());
    }

    public function test_update_saves_bloom_level_and_co_on_a_leaf_when_present_and_leaves_them_null_when_not(): void
    {
        $this->actingAdmin();
        $paper = QuestionPaper::factory()->create();
        $course = Course::factory()->create();

        $response = $this->withApiKey()->putJson("/api/v1/question-papers/{$paper->id}", [
            'exam_year' => 2025,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'groups' => [
                [
                    'label' => 'Group A',
                    'mode' => 'all',
                    'children' => [
                        ['label' => '1(i)', 'mode' => 'leaf', 'marks' => 2, 'bloom_level' => 'K1', 'co' => 'CO1'],
                        ['label' => '1(ii)', 'mode' => 'leaf', 'marks' => 2], // no bloom_level/co supplied at all
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.groups.0.children.0.bloom_level', 'K1')
            ->assertJsonPath('data.groups.0.children.0.co', 'CO1')
            ->assertJsonPath('data.groups.0.children.1.bloom_level', null)
            ->assertJsonPath('data.groups.0.children.1.co', null);

        $children = $paper->groups()->first()->children;
        $this->assertSame('K1', $children[0]->bloom_level);
        $this->assertSame('CO1', $children[0]->co);
        $this->assertNull($children[1]->bloom_level);
        $this->assertNull($children[1]->co);
    }

    public function test_update_saves_a_three_way_alternative_node(): void
    {
        // "4)i) OR 4)ii) OR 4)iii)" — one question with a 3-way OR, nested
        // inside a group where every OTHER question is a plain leaf.
        $this->actingAdmin();
        $paper = QuestionPaper::factory()->create();
        $course = Course::factory()->create();

        $response = $this->withApiKey()->putJson("/api/v1/question-papers/{$paper->id}", [
            'exam_year' => 2025,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'groups' => [
                [
                    'label' => 'Group B',
                    'mode' => 'all',
                    'children' => [
                        ['label' => '3', 'mode' => 'leaf', 'marks' => 5],
                        [
                            'label' => '4',
                            'mode' => 'choose',
                            'choose_count' => 1,
                            'children' => [
                                ['label' => 'i', 'mode' => 'leaf', 'marks' => 5],
                                ['label' => 'ii', 'mode' => 'leaf', 'marks' => 5],
                                ['label' => 'iii', 'mode' => 'leaf', 'marks' => 5],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertOk();
        $groupB = QuestionPaper::first()->groups()->first();
        $plainQuestion = $groupB->children()->where('label', '3')->first();
        $this->assertSame('leaf', $plainQuestion->mode);
        $altQuestion = $groupB->children()->where('label', '4')->first();
        $this->assertSame('choose', $altQuestion->mode);
        $this->assertSame(1, $altQuestion->choose_count);
        $this->assertSame(3, $altQuestion->children()->count());
    }

    public function test_update_saves_an_alternative_nested_inside_a_part(): void
    {
        // "2)i)a) OR 2)i)b)" — three levels deep: question "2" -> part "i" -> lettered alternatives "a"/"b".
        $this->actingAdmin();
        $paper = QuestionPaper::factory()->create();
        $course = Course::factory()->create();

        $response = $this->withApiKey()->putJson("/api/v1/question-papers/{$paper->id}", [
            'exam_year' => 2025,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'groups' => [
                [
                    'label' => 'Group B',
                    'mode' => 'all',
                    'children' => [
                        [
                            'label' => '2',
                            'mode' => 'all',
                            'children' => [
                                [
                                    'label' => 'i',
                                    'mode' => 'choose',
                                    'choose_count' => 1,
                                    'children' => [
                                        ['label' => 'a', 'mode' => 'leaf', 'marks' => 5],
                                        ['label' => 'b', 'mode' => 'leaf', 'marks' => 5],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertOk();
        $question2 = QuestionPaper::first()->groups()->first()->children()->where('label', '2')->first();
        $this->assertSame('all', $question2->mode);
        $partI = $question2->children()->first();
        $this->assertSame('i', $partI->label);
        $this->assertSame('choose', $partI->mode);
        $this->assertSame(['a', 'b'], $partI->children()->pluck('label')->all());
    }

    public function test_update_saves_a_pooled_choose_n_across_a_combined_group(): void
    {
        // "Groups C, D & E together have 15 questions; answer any 12
        // combined" — choose_count always applies to a node's own direct
        // children, so a combined pool is just one group node whose direct
        // children are all 15 leaves (this also matches how such combined
        // sections are usually printed — one heading, one continuous list).
        $this->actingAdmin();
        $paper = QuestionPaper::factory()->create();
        $course = Course::factory()->create();

        $response = $this->withApiKey()->putJson("/api/v1/question-papers/{$paper->id}", [
            'exam_year' => 2025,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'groups' => [
                [
                    'label' => 'Groups C, D & E',
                    'instruction' => 'Answer any 12 of the following 15 questions.',
                    'mode' => 'choose',
                    'choose_count' => 12,
                    'children' => array_map(
                        fn ($n) => ['label' => (string) $n, 'mode' => 'leaf', 'marks' => 4],
                        range(11, 25),
                    ),
                ],
            ],
        ]);

        $response->assertOk();
        $pool = QuestionPaper::first()->groups()->first();
        $this->assertSame(15, $pool->children()->count());
        $this->assertSame(12, $pool->choose_count);
    }

    public function test_update_saves_a_choose_n_pool_wrapping_labeled_sub_groups_with_nested_or(): void
    {
        // The other pooling shape: "Groups C, D & E" each want to stay their
        // own labeled, separately-instructed section (not flattened into
        // one continuous list), while the paper still says "answer any 11
        // of the 15 combined" — so the outer choose node's 3 direct
        // children are themselves "all" sub-groups (5 slots each), two of
        // which also contain their own OR-alternative inside. choose_count
        // must be validated against the *slot* count (15) here, not the
        // direct children count (3) — see ValidatesQuestionPaperNodes::slotCount().
        $this->actingAdmin();
        $paper = QuestionPaper::factory()->create();
        $course = Course::factory()->create();

        $subGroup = function (string $label, array $children) {
            return ['label' => $label, 'mode' => 'all', 'instruction' => "Instruction for {$label}", 'children' => $children];
        };
        $leaf = fn (string $label, int $marks = 2) => ['label' => $label, 'mode' => 'leaf', 'marks' => $marks];
        $or = fn (string $label, array $alternatives) => ['label' => $label, 'mode' => 'choose', 'choose_count' => 1, 'children' => $alternatives];

        $response = $this->withApiKey()->putJson("/api/v1/question-papers/{$paper->id}", [
            'exam_year' => 2025,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'groups' => [
                [
                    'label' => 'Groups C, D & E',
                    'instruction' => 'Answer any 11 of the following 15 questions.',
                    'mode' => 'choose',
                    'choose_count' => 11,
                    'children' => [
                        $subGroup('Group C', [
                            $or('1', [$leaf('1(i)'), $leaf('1(ii)')]),
                            $leaf('2'),
                            $leaf('3'),
                            $or('4', [$leaf('4(i)'), $leaf('4(ii)'), $leaf('4(iii)')]),
                            $leaf('5'),
                        ]),
                        $subGroup('Group D', [
                            $leaf('6'), $leaf('7'), $leaf('8'), $leaf('9'), $leaf('10'),
                        ]),
                        $subGroup('Group E', [
                            $leaf('11'), $leaf('12'), $leaf('13'), $leaf('14'),
                            $or('15', [$leaf('15(i)'), $leaf('15(ii)')]),
                        ]),
                    ],
                ],
            ],
        ]);

        $response->assertOk();

        $pool = QuestionPaper::first()->groups()->first();
        $this->assertSame('choose', $pool->mode);
        $this->assertSame(11, $pool->choose_count);
        $this->assertSame(3, $pool->children()->count()); // Group C, D, E — not flattened

        $subGroups = $pool->children()->orderBy('sort_order')->get();
        $this->assertSame(['Group C', 'Group D', 'Group E'], $subGroups->pluck('label')->all());
        $this->assertSame(5, $subGroups[0]->children()->count()); // 1(OR), 2, 3, 4(OR), 5
        $this->assertSame(5, $subGroups[1]->children()->count());
        $this->assertSame(5, $subGroups[2]->children()->count());
    }

    public function test_update_rejects_a_choose_count_larger_than_the_pooled_slot_count(): void
    {
        // Same shape as above (3 sub-groups of 5 slots = 15 total slots),
        // but choose_count of 16 exceeds the pool — must still be rejected
        // even though it's well under the raw direct-children count (3).
        $this->actingAdmin();
        $paper = QuestionPaper::factory()->create();
        $course = Course::factory()->create();

        $subGroup = fn (string $label) => [
            'label' => $label,
            'mode' => 'all',
            'children' => array_map(fn ($n) => ['label' => (string) $n, 'mode' => 'leaf', 'marks' => 2], range(1, 5)),
        ];

        $response = $this->withApiKey()->putJson("/api/v1/question-papers/{$paper->id}", [
            'exam_year' => 2025,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'groups' => [
                [
                    'label' => 'Groups C, D & E',
                    'mode' => 'choose',
                    'choose_count' => 16,
                    'children' => [$subGroup('Group C'), $subGroup('Group D'), $subGroup('Group E')],
                ],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('groups.0.choose_count');
    }

    public function test_update_replaces_the_structure_instead_of_appending(): void
    {
        $this->actingAdmin();
        $paper = QuestionPaper::factory()->create();
        $course = Course::factory()->create();
        $oldGroup = QuestionPaperNode::create(['question_paper_id' => $paper->id, 'parent_id' => null, 'label' => 'Old Group', 'mode' => 'all', 'sort_order' => 0]);
        QuestionPaperNode::create(['question_paper_id' => $paper->id, 'parent_id' => $oldGroup->id, 'label' => '1', 'mode' => 'leaf', 'marks' => 10, 'sort_order' => 0]);

        $this->withApiKey()->putJson("/api/v1/question-papers/{$paper->id}", [
            'exam_year' => 2025,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'groups' => [
                ['label' => 'New Group', 'mode' => 'all', 'children' => [['label' => '1', 'mode' => 'leaf', 'marks' => 5]]],
            ],
        ]);

        $paper->refresh();
        $this->assertSame(1, $paper->groups()->count());
        $this->assertSame('New Group', $paper->groups()->first()->label);
        $this->assertSame(2, QuestionPaperNode::where('question_paper_id', $paper->id)->count()); // 1 group + 1 leaf, old tree gone
    }

    public function test_update_requires_choose_count_for_a_choose_node(): void
    {
        $this->actingAdmin();
        $paper = QuestionPaper::factory()->create();
        $course = Course::factory()->create();

        $response = $this->withApiKey()->putJson("/api/v1/question-papers/{$paper->id}", [
            'exam_year' => 2025,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'groups' => [
                [
                    'label' => 'Group A',
                    'mode' => 'choose',
                    'children' => [
                        ['label' => '1', 'mode' => 'leaf', 'marks' => 2],
                        ['label' => '2', 'mode' => 'leaf', 'marks' => 2],
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('groups.0.choose_count');
    }

    public function test_update_rejects_a_choose_count_larger_than_the_children_count(): void
    {
        $this->actingAdmin();
        $paper = QuestionPaper::factory()->create();
        $course = Course::factory()->create();

        $response = $this->withApiKey()->putJson("/api/v1/question-papers/{$paper->id}", [
            'exam_year' => 2025,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'groups' => [
                [
                    'label' => 'Group A',
                    'mode' => 'choose',
                    'choose_count' => 5,
                    'children' => [
                        ['label' => '1', 'mode' => 'leaf', 'marks' => 2],
                        ['label' => '2', 'mode' => 'leaf', 'marks' => 2],
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('groups.0.choose_count');
    }

    public function test_update_rejects_a_choose_node_with_only_one_option(): void
    {
        // A "choose" with a single child isn't a real alternative — needs >= 2.
        $this->actingAdmin();
        $paper = QuestionPaper::factory()->create();
        $course = Course::factory()->create();

        $response = $this->withApiKey()->putJson("/api/v1/question-papers/{$paper->id}", [
            'exam_year' => 2025,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'groups' => [
                [
                    'label' => 'Group A',
                    'mode' => 'choose',
                    'choose_count' => 1,
                    'children' => [
                        ['label' => '1', 'mode' => 'leaf', 'marks' => 2],
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('groups.0.children');
    }

    public function test_update_validates_a_leaf_missing_marks_at_any_depth(): void
    {
        $this->actingAdmin();
        $paper = QuestionPaper::factory()->create();
        $course = Course::factory()->create();

        $response = $this->withApiKey()->putJson("/api/v1/question-papers/{$paper->id}", [
            'exam_year' => 2025,
            'course_id' => $course->id,
            'semester' => 5,
            'exam_term_id' => ExamTerm::factory()->create()->id,
            'groups' => [
                [
                    'label' => 'Group A',
                    'mode' => 'all',
                    'children' => [
                        [
                            'label' => '1',
                            'mode' => 'choose',
                            'choose_count' => 1,
                            'children' => [
                                ['label' => 'i', 'mode' => 'leaf'], // marks missing, 3 levels deep
                                ['label' => 'ii', 'mode' => 'leaf', 'marks' => 5],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('groups.0.children.0.children.0.marks');
    }

    public function test_admin_can_soft_delete_a_question_paper(): void
    {
        $this->actingAdmin();
        $paper = QuestionPaper::factory()->create();

        $response = $this->withApiKey()->deleteJson("/api/v1/question-papers/{$paper->id}");

        $response->assertOk();
        $this->assertSoftDeleted('question_papers', ['id' => $paper->id]);
    }

    public function test_soft_deleted_question_paper_is_excluded_from_default_listing(): void
    {
        $this->actingAdmin();
        $paper = QuestionPaper::factory()->create();
        $paper->delete();

        $response = $this->withApiKey()->getJson('/api/v1/question-papers');

        $response->assertOk();
        $this->assertFalse(collect($response->json('data.items'))->pluck('id')->contains($paper->id));
    }

    public function test_admin_can_restore_a_soft_deleted_question_paper(): void
    {
        $this->actingAdmin();
        $paper = QuestionPaper::factory()->create();
        $paper->delete();

        $response = $this->withApiKey()->postJson("/api/v1/question-papers/{$paper->id}/restore");

        $response->assertOk();
        $this->assertDatabaseHas('question_papers', ['id' => $paper->id, 'deleted_at' => null]);
    }

    public function test_force_deleting_a_question_paper_removes_its_pdf_and_entire_structure_tree(): void
    {
        Storage::fake('public');
        $this->actingAdmin();
        Storage::disk('public')->put('question-papers/existing.pdf', 'fake-pdf-content');
        $paper = QuestionPaper::factory()->create(['pdf_path' => '/storage/question-papers/existing.pdf']);
        $group = QuestionPaperNode::create(['question_paper_id' => $paper->id, 'parent_id' => null, 'label' => 'Group A', 'mode' => 'all', 'sort_order' => 0]);
        $question = QuestionPaperNode::create(['question_paper_id' => $paper->id, 'parent_id' => $group->id, 'label' => '1', 'mode' => 'choose', 'choose_count' => 1, 'sort_order' => 0]);
        QuestionPaperNode::create(['question_paper_id' => $paper->id, 'parent_id' => $question->id, 'label' => 'i', 'mode' => 'leaf', 'marks' => 2, 'sort_order' => 0]);
        QuestionPaperNode::create(['question_paper_id' => $paper->id, 'parent_id' => $question->id, 'label' => 'ii', 'mode' => 'leaf', 'marks' => 2, 'sort_order' => 1]);

        $paper->forceDelete();

        Storage::disk('public')->assertMissing('question-papers/existing.pdf');
        $this->assertDatabaseCount('question_paper_nodes', 0); // every depth gone, not just the top-level group
    }
}
