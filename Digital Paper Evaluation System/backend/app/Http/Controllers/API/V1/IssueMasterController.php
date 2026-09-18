<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\IssueMaster;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Read-only — populates EvaluatePaperView.vue's "Problem" modal dropdown.
 * No create/edit/delete here or anywhere in the UI yet; issue_masters is
 * seeded once by IssueMasterSeeder (see config('issues.*') for its
 * well-known ids) and not otherwise managed.
 */
class IssueMasterController extends Controller
{
    use ApiResponse;

    /**
     * Ordered by id, not name — Printing Issue is always id 1 (see
     * config('issues.printing_issue_id')), so the frontend's "default to
     * the first row" locked-dropdown behavior is genuinely id-based, not
     * a coincidence of alphabetical sorting.
     */
    public function index(): JsonResponse
    {
        return $this->success(
            IssueMaster::where('status', true)->orderBy('id')->get(['id', 'name']),
            'Issue types fetched successfully.',
        );
    }
}
