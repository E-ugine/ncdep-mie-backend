<?php

namespace App\Models;

use App\Enums\ModuleAccessAttemptType;
use App\Enums\ModuleAccessOutcome;
use Database\Factories\ModuleAccessLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'attempt_type', 'outcome', 'ip_address', 'user_agent'])]
class ModuleAccessLog extends Model
{
    /** @use HasFactory<ModuleAccessLogFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'attempt_type' => ModuleAccessAttemptType::class,
            'outcome' => ModuleAccessOutcome::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
