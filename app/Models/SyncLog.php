<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'model_name',
        'model_id',
        'action', // 'create', 'update', 'delete'
        'sync_status', // 'pending', 'syncing', 'completed', 'failed'
        'sync_attempts',
        'last_sync_attempt',
        'error_message',
        'data', // JSON data of the record
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'sync_attempts' => 'integer',
        'last_sync_attempt' => 'datetime',
    ];

    /**
     * Get the data attribute as array
     */
    public function getDataAttribute($value)
    {
        return $value ? json_decode($value, true) : null;
    }

    /**
     * Set the data attribute as JSON string
     */
    public function setDataAttribute($value)
    {
        $this->attributes['data'] = $value ? json_encode($value) : null;
    }

    /**
     * Mark sync log as pending
     */
    public function markAsPending(): void
    {
        $this->update([
            'sync_status' => 'pending',
            'sync_attempts' => 0,
            'error_message' => null
        ]);
    }

    /**
     * Mark sync log as syncing
     */
    public function markAsSyncing(): void
    {
        $this->update([
            'sync_status' => 'syncing',
            'sync_attempts' => $this->sync_attempts + 1,
            'last_sync_attempt' => now()
        ]);
    }

    /**
     * Mark sync log as completed
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'sync_status' => 'completed',
            'error_message' => null
        ]);
    }

    /**
     * Mark sync log as failed
     */
    public function markAsFailed(string $errorMessage = null): void
    {
        $this->update([
            'sync_status' => 'failed',
            'error_message' => $errorMessage
        ]);
    }

    /**
     * Get pending sync logs for a specific model
     */
    public static function getPendingForModel(string $modelName): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('model_name', $modelName)
            ->whereIn('sync_status', ['pending', 'failed'])
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Get all pending sync logs
     */
    public static function getAllPending(): \Illuminate\Database\Eloquent\Collection
    {
        return static::whereIn('sync_status', ['pending', 'failed'])
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Clean up old completed sync logs
     */
    public static function cleanupOldLogs(int $daysOld = 7): int
    {
        return static::where('sync_status', 'completed')
            ->where('updated_at', '<', now()->subDays($daysOld))
            ->delete();
    }

    /**
     * Get sync statistics
     */
    public static function getSyncStats(): array
    {
        return [
            'pending' => static::where('sync_status', 'pending')->count(),
            'syncing' => static::where('sync_status', 'syncing')->count(),
            'completed' => static::where('sync_status', 'completed')->count(),
            'failed' => static::where('sync_status', 'failed')->count(),
            'total' => static::count()
        ];
    }
}
