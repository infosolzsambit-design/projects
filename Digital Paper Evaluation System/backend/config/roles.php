<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Well-Known Role IDs
    |--------------------------------------------------------------------------
    |
    | Only Super Admin needs a fixed id — it's the one role with actual
    | code-level special treatment (Gate::before in AppServiceProvider
    | bypasses every permission check for it; HasExamYearScope skips
    | exam-year scoping for it; the login response's is_super_admin flag
    | reflects it), all checked by id, never by role name, via Spatie's
    | own hasRole(), which accepts an array of ids and matches on any of
    | them. Every other role (Teacher included) is fully dynamic — created,
    | renamed, and granted whatever permissions an admin picks entirely
    | through the Roles/Permissions screens, with no id anywhere in code
    | assuming which role is "the" Teacher role.
    |
    | This is a *list* — every id in it gets the Super Admin treatment
    | above. Only the *first* id (index 0) is the one RoleSeeder.php/
    | AdminUserSeeder.php actually create/seed as "Super Admin" — any
    | further id (e.g. a separately created "Admin" role) is expected to
    | already exist; adding it here only grants it the same authorization
    | treatment, it doesn't create or rename anything.
    |
    */

    'super_admin_id' => [1, 5],

    /*
    |--------------------------------------------------------------------------
    | Roles That Get the "Issue Raised" Email
    |--------------------------------------------------------------------------
    |
    | MyPendingCourseController::raiseIssue() sends every user holding any
    | of these role ids an email the moment a teacher raises a Printing or
    | Timing issue (see IssueRaisedMailService) — resolved fresh per send
    | (every user currently in any of these roles gets it, not a fixed
    | admin list), same "resolve by id, never by name" convention as
    | super_admin_id above.
    |
    */

    'admin_recived_issue_mail' => [1, 5],

];
