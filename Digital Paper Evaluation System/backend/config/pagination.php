<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pagination Defaults
    |--------------------------------------------------------------------------
    |
    | Default and maximum "per_page" values for paginated API listings.
    | Controllers must clamp client-supplied per_page against the max so
    | the frontend cannot request an unbounded dataset in one call.
    |
    */

    'default_per_page' => (int) env('PAGINATION_DEFAULT_PER_PAGE', 20),

    'max_per_page' => (int) env('PAGINATION_MAX_PER_PAGE', 30),

];
