<?php

namespace Database\Seeders;

use App\Models\IssueMaster;
use Illuminate\Database\Seeder;

class IssueMasterSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedOne((int) config('issues.printing_issue_id'), 'Printing Issue');
        $this->seedOne((int) config('issues.timing_issue_id'), 'Timing Issue');
    }

    private function seedOne(int $id, string $name): void
    {
        $issue = IssueMaster::withTrashed()->find($id);

        if (! $issue) {
            // forceCreate to set the explicit, well-known ID — resolved by
            // id elsewhere (see config('issues.*')), never by matching this
            // name string.
            IssueMaster::forceCreate([
                'id' => $id,
                'name' => $name,
                'status' => true,
            ]);
        } elseif ($issue->trashed()) {
            $issue->restore();
        }
    }
}
