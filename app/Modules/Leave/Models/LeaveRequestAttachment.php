<?php

declare(strict_types=1);

namespace App\Modules\Leave\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to a leave request. Uploads and removals are audited (the
 * file name only; the content never enters the audit log).
 *
 * @property int $id
 * @property int $leave_request_id
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $path
 * @property int|null $uploaded_by
 * @property CarbonImmutable $created_at
 * @property-read LeaveRequest $request
 * @property-read User|null $uploader
 */
class LeaveRequestAttachment extends Model
{
    use Auditable;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s';

    protected $guarded = [];

    /** @var list<string> */
    protected array $auditIgnore = ['path'];

    protected function casts(): array
    {
        return [
            'leave_request_id' => 'integer',
            'size_bytes' => 'integer',
            'uploaded_by' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<LeaveRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }
}
