<?php

namespace Database\Seeders;

use App\Models\DocumentCounter;
use App\Services\DocumentNumberService;
use Illuminate\Database\Seeder;

/** Section 7b: one row per prefix; each sequence is independent. */
class DocumentCounterSeeder extends Seeder
{
    public function run(): void
    {
        foreach (DocumentNumberService::ALL_PREFIXES as $prefix) {
            DocumentCounter::firstOrCreate(['prefix' => $prefix], ['next_number' => 1]);
        }
    }
}
