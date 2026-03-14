<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DocumentBatchItemActivityLog extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentBatchItemActivityLogFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'document_batch_id',
        'document_batch_item_id',
        'user_id',
        'action',
        'summary',
        'details',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $log): void {
            if (! is_string($log->uuid) || $log->uuid === '') {
                $log->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
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
     * @return BelongsTo<DocumentBatchItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(DocumentBatchItem::class, 'document_batch_item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
