# SyncLog System Documentation

## Overview

The SyncLog system is designed to solve the concurrency issue where newly created records might be missed during synchronization. Instead of relying on timestamp-based synchronization, this system tracks every model change in a dedicated `sync_logs` table.

## Components

### 1. SyncLog Model (`app/Models/SyncLog.php`)

The SyncLog model tracks all changes that need to be synchronized:

-   **model_name**: Full class name of the model (e.g., `App\Models\Product`)
-   **model_id**: ID of the specific record
-   **action**: Type of operation (`create`, `update`, `delete`)
-   **sync_status**: Current sync status (`pending`, `syncing`, `completed`, `failed`)
-   **sync_attempts**: Number of sync attempts made
-   **last_sync_attempt**: Timestamp of last sync attempt
-   **error_message**: Error message if sync failed
-   **data**: JSON data of the record (for delete operations)

### 2. Syncable Trait (`app/Traits/Syncable.php`)

The Syncable trait automatically logs model changes:

```php
use App\Traits\Syncable;

class Product extends Model
{
    use Syncable;
    // ... rest of model
}
```

**Features:**

-   Automatically logs `created`, `updated`, and `deleted` events
-   Prevents infinite loops during sync operations
-   Handles concurrent operations safely
-   Updates existing pending logs instead of creating duplicates

### 3. Updated SyncService

The SyncService now uses SyncLog instead of timestamp-based synchronization:

-   **getLocalChangesForModel()**: Gets pending sync logs instead of timestamp queries
-   **markSyncLogsAsCompleted()**: Marks successful syncs as completed
-   **markSyncLogsAsFailed()**: Marks failed syncs with error messages
-   **cleanupOldSyncLogs()**: Removes old completed sync logs

## How It Works

### 1. Model Changes Are Logged

When a model using the Syncable trait is modified:

```php
$product = new Product();
$product->name = 'New Product';
$product->save(); // Automatically creates a sync log entry
```

### 2. Sync Process

1. **Discovery**: SyncService gets all pending sync logs
2. **Marking**: Logs are marked as "syncing" to prevent duplicate processing
3. **Data Retrieval**: Fresh data is retrieved from the database
4. **Synchronization**: Data is sent to the master server
5. **Completion**: Logs are marked as "completed" or "failed"

### 3. Concurrency Safety

-   Records created during sync are automatically logged
-   No records are missed due to timing issues
-   Failed syncs can be retried
-   Duplicate syncs are prevented

## Setup Instructions

### 1. Run Migration

```bash
php artisan migrate
```

### 2. Add Syncable Trait to Models

Add the trait to models you want to sync:

```php
use App\Traits\Syncable;

class YourModel extends Model
{
    use Syncable;
    // ... rest of model
}
```

### 3. Configure Models

The system automatically discovers models, but you can exclude specific models in `SyncService`:

```php
protected $excludedModels = [
    'PasswordReset',
    'OauthAccessToken',
    'OauthRefreshToken',
    'role_user',
    'sms_gateway',
    'Server'
];
```

## Scheduled Tasks

The system includes automatic cleanup:

-   **Sync Log Cleanup**: Daily at 3:00 AM, removes completed logs older than 7 days
-   **Regular Sync**: Every 5 minutes (only on slave instances)

## Monitoring and Statistics

### Get Sync Statistics

```php
$syncService = app(\App\Services\SyncService::class);
$stats = $syncService->getSyncLogStats();

// Returns:
// [
//     'pending' => 5,
//     'syncing' => 2,
//     'completed' => 150,
//     'failed' => 3,
//     'total' => 160
// ]
```

### View Sync Logs

```php
// Get all pending sync logs
$pendingLogs = \App\Models\SyncLog::getAllPending();

// Get pending logs for specific model
$productLogs = \App\Models\SyncLog::getPendingForModel('App\Models\Product');
```

## Benefits

### 1. **No Missed Records**

-   Every model change is tracked
-   Records created during sync are automatically included
-   No timing-based race conditions

### 2. **Reliable Retry Mechanism**

-   Failed syncs are tracked and can be retried
-   Error messages are stored for debugging
-   Sync attempts are counted

### 3. **Performance Optimized**

-   Indexed database queries
-   Automatic cleanup of old logs
-   Efficient batch processing

### 4. **Comprehensive Logging**

-   Full audit trail of sync operations
-   Detailed error reporting
-   Statistics and monitoring

## Troubleshooting

### Common Issues

1. **High Number of Pending Logs**

    - Check network connectivity
    - Verify master server is accessible
    - Review error messages in failed logs

2. **Sync Failures**

    - Check `error_message` field in sync_logs table
    - Verify data format compatibility
    - Ensure master server API is working

3. **Performance Issues**
    - Monitor sync_logs table size
    - Adjust cleanup frequency if needed
    - Check database indexes

### Manual Operations

```php
// Reset failed sync logs to pending
\App\Models\SyncLog::where('sync_status', 'failed')
    ->update(['sync_status' => 'pending', 'error_message' => null]);

// Clean up old logs manually
$syncService = app(\App\Services\SyncService::class);
$deleted = $syncService->cleanupOldSyncLogs(3); // Keep only 3 days
```

## Migration from Timestamp-Based Sync

The system is backward compatible. To fully migrate:

1. Add Syncable trait to all models
2. Run initial sync to populate sync_logs
3. Monitor for any missed records
4. Remove timestamp-based sync logic when confident

## Security Considerations

-   Sync logs contain sensitive data - ensure proper access controls
-   Consider encrypting the `data` field for sensitive models
-   Regular cleanup prevents data accumulation
-   Monitor sync logs for unusual patterns
