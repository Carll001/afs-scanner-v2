<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DocumentBatchItem extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentBatchItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'document_batch_id',
        'row_number',
        'row_data',
        'status',
        'docx_path',
        'pdf_path',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $item): void {
            if (! is_string($item->uuid) || $item->uuid === '') {
                $item->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'row_data' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DocumentBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(DocumentBatch::class, 'document_batch_id');
    }

    /**
     * @return HasMany<DocumentBatchItemActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(DocumentBatchItemActivityLog::class, 'document_batch_item_id');
    }
}
