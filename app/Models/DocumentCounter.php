<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Section 7b: one row per prefix, each sequence independent.
 * See App\Services\DocumentNumberService for the generation rules.
 */
#[Fillable(['prefix', 'next_number'])]
class DocumentCounter extends Model
{
    protected function casts(): array
    {
        return ['next_number' => 'integer'];
    }
}
