# Laravel Task Scheduling Setup

This document explains the scheduled tasks that have been configured for the application.

## Configuration

### Environment Variables

Add these variables to your `.env` file:

```env
# Application mode - set to 'slave' for instances that should sync with master
APP_MODE=master

# Sync timeout in seconds (default: 25)
SYNC_TIMEOUT=25

# Enable/disable sync logging (default: true)
SYNC_LOGGING_ENABLED=true
```

## Scheduled Tasks

### 1. Database Sync (Every 5 Minutes)

-   **Frequency**: Every 5 minutes
-   **Condition**: Only runs on slave instances (`APP_MODE=slave`)
-   **Purpose**: Syncs data with the master server using the SyncService
-   **Logging**: Logs sync results and skips if not a slave instance

### 2. Log Cleanup (Daily at 2:00 AM)

-   **Frequency**: Daily at 2:00 AM
-   **Purpose**: Removes log files older than 7 days
-   **Location**: `storage/logs/`
-   **Logging**: Logs each deleted file and completion status

### 3. Temporary Files Cleanup (Weekly)

-   **Frequency**: Weekly
-   **Purpose**: Removes temporary files older than 3 days from:
    -   `storage/framework/cache/`
    -   `storage/framework/sessions/`
    -   `storage/framework/views/`
-   **Logging**: Logs completion status

### 4. Database Backup (Existing)

-   **Frequency**: As configured in the existing command
-   **Purpose**: Database backup using the existing `database:backup` command

## Running the Scheduler

To run the scheduled tasks, you need to set up a cron job on your server:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

## Testing

You can test the scheduled tasks manually:

```bash
# Run all scheduled tasks
php artisan schedule:run

# List all scheduled tasks
php artisan schedule:list
```

## Notes

-   The sync task will only execute on slave instances to prevent conflicts
-   All tasks include comprehensive logging for monitoring and debugging
-   The SyncService handles network connectivity checks and error handling
-   Temporary file cleanup helps maintain disk space and performance
