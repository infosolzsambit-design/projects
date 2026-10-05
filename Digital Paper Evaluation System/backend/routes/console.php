<?php

use App\Helpers\PublicStorage;
use App\Models\AnswerSheet;
use App\Services\StudentIdCheckService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

// Terminal alternative to the Generate Marksheet page's own check: reads
// the handwritten student ID right now, in batches, with a progress bar.
// Manually confirmed sheets are never re-read.
Artisan::command('answer-sheets:check-student-ids {--mapping= : Only this packet (question_answer_sheet_mapping id)} {--recheck : Also re-read sheets already checked}', function (StudentIdCheckService $service) {
    $query = AnswerSheet::query()->whereNotNull('pdf_path')->whereNull('student_id_verified_by');
    if ($this->option('mapping')) {
        $query->where('question_answer_sheet_mapping_id', (int) $this->option('mapping'));
    }
    if (! $this->option('recheck')) {
        $query->whereNull('roll_no_check_status');
    }

    $bar = $this->output->createProgressBar((clone $query)->count());
    $query->select(['id', 'question_answer_sheet_mapping_id', 'pdf_path'])->chunkById(25, function ($sheets) use ($service, $bar) {
        $service->checkMany($sheets);
        $bar->advance($sheets->count());
    });
    $bar->finish();
    $this->newLine();
    $this->info('Student ID check complete.');
})->purpose('Read the handwritten student ID on answer sheets and compare it with the roll number');

// One-off fix for upload folders created before PublicStorage existed:
// opens every folder under public/storage so the FTP account can delete
// inside it. New uploads do this automatically.
Artisan::command('storage:open-folders', function () {
    $this->info(PublicStorage::openAllFolders().' folder(s) opened for FTP deletion.');
})->purpose('Make every upload folder under public/storage deletable over FTP');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
