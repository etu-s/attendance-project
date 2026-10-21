<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Modules\Organization\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A CSV import run. Rows that fail validation are skipped and reported in
 * `errors` ([{row, messages}]); valid rows are created.
 *
 * @property int $id
 * @property int|null $user_id
 * @property ImportStatus $status
 * @property string|null $original_name
 * @property string|null $contents
 * @property int $total_rows
 * @property int $created_count
 * @property int $failed_count
 * @property list<array{row: int, code: string|null, messages: list<string>}>|null $errors
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 */
class EmployeeImport extends Model
{
    protected $fillable = [
        'user_id', 'status', 'original_name', 'contents', 'total_rows',
        'created_count', 'failed_count', 'errors', 'started_at', 'finished_at',
    ];

    protected $hidden = ['contents'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'user_id' => 'integer',
            'total_rows' => 'integer',
            'created_count' => 'integer',
            'failed_count' => 'integer',
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
