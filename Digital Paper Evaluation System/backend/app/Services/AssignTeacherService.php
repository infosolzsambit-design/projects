<?php

namespace App\Services;

use App\Models\AnswerSheet;
use App\Models\QuestionAnswerSheetMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Backs AssignTeacherController::store() — the actual persistence behind
 * AssignTeacherView.vue's "Assign" button (Distribute Equally / hand-edited
 * quantities are worked out entirely client-side; this is only ever handed
 * the final {teacher_id, quantity} list to commit).
 *
 * There's no separate "assignment" table — assigning N answer sheets to a
 * teacher means picking N still-pending (teacher_id IS NULL) AnswerSheet
 * rows out of whichever packet(s) match the search's five exam-detail
 * fields, and stamping teacher_id on them. That keeps "how many are still
 * pending" a plain count query (see
 * QuestionAnswerSheetMappingResource::pending_answer_sheet_count) instead
 * of needing to reconcile against a second table.
 *
 * Every sheet touched by assign() also gets the same evaluation_start_date/
 * evaluation_end_date stamped on it — the window the admin picked on the
 * page before clicking "Assign", stored per-sheet so a later "change
 * timings" feature can edit it per sheet/teacher rather than only at
 * assignment time. evaluation_time_per_sheet rides along the same way —
 * the expected minutes-per-sheet the admin entered alongside that window
 * (see AssignTeacherView.vue's own field for it), purely informational for
 * now.
 */
class AssignTeacherService
{
    /**
     * @param  array{program_name:string,exam_term_id:int,course_id:int,semester:int,exam_year:int}  $filters
     * @param  list<array{teacher_id:int,quantity:int}>  $assignments
     * @return list<array{teacher_id:int,assigned_count:int}>
     *
     * @throws ValidationException when nothing matches the filters, or more
     *                              sheets are requested than are still
     *                              pending (e.g. someone else's "Assign"
     *                              click already claimed some in between
     *                              this admin loading the page and
     *                              clicking their own "Assign").
     */
    public function assign(array $filters, array $assignments, string $evaluationStartDate, string $evaluationEndDate, ?int $evaluationTimePerSheet = null): array
    {
        return DB::transaction(function () use ($filters, $assignments, $evaluationStartDate, $evaluationEndDate, $evaluationTimePerSheet) {
            $mappingIds = QuestionAnswerSheetMapping::query()
                ->where('program_name', $filters['program_name'])
                ->where('exam_term_id', $filters['exam_term_id'])
                ->where('course_id', $filters['course_id'])
                ->where('semester', $filters['semester'])
                ->whereHas('questionPaper', fn ($q) => $q->where('exam_year', $filters['exam_year']))
                ->pluck('id');

            if ($mappingIds->isEmpty()) {
                throw ValidationException::withMessages([
                    'assignments' => ['No answer sheet packet matches these exam details.'],
                ]);
            }

            // Locked so two admins racing to assign the same course's
            // sheets at once can't both claim the same physical sheet.
            $pendingSheets = AnswerSheet::whereIn('question_answer_sheet_mapping_id', $mappingIds)
                ->whereNull('teacher_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $totalRequested = collect($assignments)->sum('quantity');

            if ($totalRequested > $pendingSheets->count()) {
                throw ValidationException::withMessages([
                    'assignments' => ["Only {$pendingSheets->count()} answer sheet(s) are still pending for this search — {$totalRequested} were requested."],
                ]);
            }

            $cursor = 0;
            $summary = [];

            foreach ($assignments as $assignment) {
                $quantity = (int) $assignment['quantity'];
                if ($quantity < 1) {
                    continue;
                }

                // Individual ->update() calls (not one query-builder mass
                // UPDATE) so each sheet still gets its own updated_by stamp
                // (HasUserstamps) and its own automatic audit-trail entry
                // (Auditable) — same reasoning as
                // QuestionAnswerSheetMapping's own soft-delete cascade.
                $slice = $pendingSheets->slice($cursor, $quantity);
                $cursor += $quantity;

                foreach ($slice as $sheet) {
                    $sheet->update([
                        'teacher_id' => $assignment['teacher_id'],
                        'evaluation_start_date' => $evaluationStartDate,
                        'evaluation_end_date' => $evaluationEndDate,
                        'evaluation_time_per_sheet' => $evaluationTimePerSheet,
                    ]);
                }

                $summary[] = [
                    'teacher_id' => (int) $assignment['teacher_id'],
                    'assigned_count' => $slice->count(),
                ];
            }

            return $summary;
        });
    }

    /**
     * Moves already-assigned sheets from one teacher to one or more others
     * within one packet — e.g. a teacher goes on sick leave and their
     * pending work for that course needs to be split across the rest of
     * the team. Unlike assign() above this never touches *pending*
     * (teacher_id IS NULL) sheets, only ones already sitting with
     * $fromTeacherId — same cursor-slicing approach as assign(), just
     * drawing from that teacher's own sheets in this packet instead of the
     * packet's unassigned pool.
     *
     * @param  list<array{teacher_id:int,quantity:int}>  $reassignments
     * @return array{from_remaining:int,summary:list<array{teacher_id:int,reassigned_count:int}>}
     *
     * @throws ValidationException when more sheets are requested across
     *                              $reassignments than are actually still
     *                              with the from-teacher in that packet
     *                              (stale page, or someone else already
     *                              moved/reassigned some in between).
     */
    public function reassign(int $mappingId, int $fromTeacherId, array $reassignments): array
    {
        return DB::transaction(function () use ($mappingId, $fromTeacherId, $reassignments) {
            $sheets = AnswerSheet::where('question_answer_sheet_mapping_id', $mappingId)
                ->where('teacher_id', $fromTeacherId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $totalRequested = collect($reassignments)->sum('quantity');

            if ($totalRequested > $sheets->count()) {
                throw ValidationException::withMessages([
                    'reassignments' => ["Only {$sheets->count()} answer sheet(s) are still with this teacher for this course — {$totalRequested} were requested."],
                ]);
            }

            $cursor = 0;
            $summary = [];

            foreach ($reassignments as $reassignment) {
                $quantity = (int) $reassignment['quantity'];
                if ($quantity < 1) {
                    continue;
                }

                $slice = $sheets->slice($cursor, $quantity);
                $cursor += $quantity;

                foreach ($slice as $sheet) {
                    $sheet->update(['teacher_id' => $reassignment['teacher_id']]);
                }

                $summary[] = [
                    'teacher_id' => (int) $reassignment['teacher_id'],
                    'reassigned_count' => $slice->count(),
                ];
            }

            return [
                'from_remaining' => $sheets->count() - $cursor,
                'summary' => $summary,
            ];
        });
    }
}
