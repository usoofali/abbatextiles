<?php

namespace App\Traits;

use App\Models\SyncLog;
use Illuminate\Database\Eloquent\Model;

trait Syncable
{
    /**
     * Boot the trait and register model events
     */
    protected static function bootSyncable()
    {
        // Log when a model is created
        static::created(function (Model $model) {
            $model->logSyncAction('create');
        });

        // Log when a model is updated
        static::updated(function (Model $model) {
            $model->logSyncAction('update');
        });

        // Log when a model is deleted
        static::deleted(function (Model $model) {
            $model->logSyncAction('delete');
        });
    }

    /**
     * Log a sync action for this model
     */
    public function logSyncAction(string $action): void
    {
        // Skip if sync logging is disabled
        if (!config('app.sync_logging_enabled', true)) {
            return;
        }

        // Skip if this is a sync operation itself (to prevent infinite loops)
        if (app()->runningInConsole() && str_contains(request()->server('argv', []), 'sync')) {
            return;
        }

        try {
            // For delete action, we need to store the data before deletion
            $data = null;
            if ($action === 'delete') {
                $data = $this->toArray();
            } else {
                $data = $this->fresh()->toArray();
            }

            // Check if there's already a pending sync log for this record
            $existingLog = SyncLog::where('model_name', static::class)
                ->where('model_id', $this->getKey())
                ->where('sync_status', 'pending')
                ->first();

            if ($existingLog) {
                // Update existing log with new data and action
                $existingLog->update([
                    'action' => $action,
                    'data' => $data,
                    'updated_at' => now()
                ]);
            } else {
                // Create new sync log
                SyncLog::create([
                    'model_name' => static::class,
                    'model_id' => $this->getKey(),
                    'action' => $action,
                    'sync_status' => 'pending',
                    'data' => $data
                ]);
            }
        } catch (\Exception $e) {
            // Log the error but don't break the main operation
            \Log::error('Failed to log sync action: ' . $e->getMessage(), [
                'model' => static::class,
                'model_id' => $this->getKey(),
                'action' => $action
            ]);
        }
    }

    /**
     * Get pending sync logs for this model instance
     */
    public function getPendingSyncLogs()
    {
        return SyncLog::where('model_name', static::class)
            ->where('model_id', $this->getKey())
            ->whereIn('sync_status', ['pending', 'failed'])
            ->get();
    }

    /**
     * Mark sync logs as completed for this model instance
     */
    public function markSyncLogsAsCompleted(): void
    {
        SyncLog::where('model_name', static::class)
            ->where('model_id', $this->getKey())
            ->whereIn('sync_status', ['pending', 'syncing'])
            ->update(['sync_status' => 'completed']);
    }

    /**
     * Mark sync logs as failed for this model instance
     */
    public function markSyncLogsAsFailed(string $errorMessage = null): void
    {
        SyncLog::where('model_name', static::class)
            ->where('model_id', $this->getKey())
            ->whereIn('sync_status', ['pending', 'syncing'])
            ->update([
                'sync_status' => 'failed',
                'error_message' => $errorMessage
            ]);
    }
}
