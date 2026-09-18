<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Evaluation Session Token TTL
    |--------------------------------------------------------------------------
    |
    | How long a marking-screen URL (/my-pending-courses/:token/evaluate)
    | stays usable after "Start Evaluate"/"Continue Evaluate" is clicked —
    | see MyPendingCourseController::startEvaluation()/showByToken(). Kept
    | generous enough to cover one real evaluation session without
    | interrupting a teacher mid-work, while still meaning a copied or
    | bookmarked link can't just be reused indefinitely.
    |
    */

    'session_token_ttl_minutes' => (int) env('EVALUATION_SESSION_TOKEN_TTL_MINUTES', 180),

];
