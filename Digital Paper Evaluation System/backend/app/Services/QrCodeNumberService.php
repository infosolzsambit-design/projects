<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Hands out the numbers Generate QR puts in each answer sheet's QR code
 * (answer_sheets.subject_barcode): starting at config('qr.start_point')
 * (START_QR_POINT in .env), then +1 each time.
 *
 * Must run inside the caller's DB transaction: the counter row is locked
 * (SELECT … FOR UPDATE) until that transaction ends, so two people
 * generating at once wait for each other instead of getting the same
 * numbers. A number some answer sheet already holds (e.g. from an upload)
 * is skipped, so every QR code stays unique.
 */
class QrCodeNumberService
{
    private const COUNTER = 'answer_sheets';

    /**
     * Locks the counter row until the transaction ends (creating it at
     * START_QR_POINT − 1 the first time). Also usable on its own as the
     * lock for "find or create this packet", so two generates for the same
     * packet can't both create it.
     */
    public function lock(): object
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('QrCodeNumberService must run inside a database transaction.');
        }

        $now = now();
        DB::table('qr_code_counters')->insertOrIgnore([
            'name' => self::COUNTER, 'last_value' => config('qr.start_point') - 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return DB::table('qr_code_counters')->where('name', self::COUNTER)->lockForUpdate()->first();
    }

    /**
     * @return list<string>
     */
    public function reserve(int $count): array
    {
        $counter = $this->lock();
        if ($count < 1) {
            return [];
        }

        $start = config('qr.start_point');
        $now = now();

        // START_QR_POINT raised later → continue from there.
        $next = max((int) $counter->last_value + 1, $start);
        $numbers = [];
        while (count($numbers) < $count) {
            $candidates = array_map('strval', range($next, $next + ($count - count($numbers)) - 1));
            $taken = DB::table('answer_sheets')->whereIn('subject_barcode', $candidates)->pluck('subject_barcode')->all();
            $numbers = array_merge($numbers, array_values(array_diff($candidates, $taken)));
            $next += count($candidates);
        }

        DB::table('qr_code_counters')->where('name', self::COUNTER)->update(['last_value' => $next - 1, 'updated_at' => $now]);

        return $numbers;
    }
}
