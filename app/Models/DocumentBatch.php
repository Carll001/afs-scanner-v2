<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DocumentBatch extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentBatchFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'source_excel_name',
        'template_name',
        'excel_path',
        'template_path',
        'sheet_index',
        'headers_json',
        'total_items',
        'processed_items',
        'success_items',
        'failed_items',
        'status',
        'started_at',
        'completed_at',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $batch): void {
            if (! is_string($batch->uuid) || $batch->uuid === '') {
                $batch->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'headers_json' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<DocumentBatchItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DocumentBatchItem::class);
    }

    /**
     * @return HasMany<DocumentBatchItemActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(DocumentBatchItemActivityLog::class);
    }
}
