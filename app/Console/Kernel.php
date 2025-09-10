<?php

namespace App\Console;

use Illuminate\Support\Facades\Cookie;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Http\Controllers\BaseController;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
        'App\Console\Commands\DatabaseBackUp',
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Database backup
        $schedule->command('database:backup');

        // Sync every 5 minutes only if this is a slave instance
        $schedule->call(function () {
            // Check the mode at runtime
            if (config('app.mode') !== 'slave') {
                \Log::info('Scheduled sync skipped - not a slave instance');
                return;
            }
            
            $syncService = app(\App\Services\SyncService::class);
            $result = $syncService->sync();
            
            // Log results
            \Log::info('Scheduled sync completed', [
                'pull_success' => $result['pull']['success'],
                'push_success' => $result['push']['success']
            ]);
        })->everyMinute();

        // Clean up old log files daily at 2:00 AM
        $schedule->call(function () {
            $logPath = storage_path('logs');
            $files = glob($logPath . '/*.log');
            $cutoff = now()->subDays(7); // Keep logs for 7 days
            
            foreach ($files as $file) {
                if (filemtime($file) < $cutoff->timestamp) {
                    unlink($file);
                    \Log::info('Cleaned up old log file: ' . basename($file));
                }
            }
            
            \Log::info('Log cleanup completed');
        })->dailyAt('02:00');

        // Clean up temporary files weekly
        $schedule->call(function () {
            $tempPaths = [
                storage_path('framework/cache'),
                storage_path('framework/sessions'),
                storage_path('framework/views')
            ];
            
            foreach ($tempPaths as $path) {
                if (is_dir($path)) {
                    $files = glob($path . '/*');
                    foreach ($files as $file) {
                        if (is_file($file) && filemtime($file) < now()->subDays(3)->timestamp) {
                            unlink($file);
                        }
                    }
                }
            }
            
            \Log::info('Temporary files cleanup completed');
        })->weekly();

        // Clean up old sync logs daily at 3:00 AM
        $schedule->call(function () {
            $syncService = app(\App\Services\SyncService::class);
            $deleted = $syncService->cleanupOldSyncLogs(7); // Keep logs for 7 days
            
            \Log::info("Sync logs cleanup completed - deleted {$deleted} old logs");
        })->dailyAt('03:00');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
