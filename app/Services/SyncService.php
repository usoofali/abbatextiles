<?php
namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionException;

class SyncService
{
    // Internal configuration
    protected $syncUrl = 'https://abbatextiles.yumitsolutions.com/api/sync';
    protected $syncTimeout = 25;
    protected $networkTestUrl = 'https://www.google.com';
    protected $networkTestTimeout = 3;
    protected $defaultStartDate = '2025-07-07 00:00:00';
    protected $dateFields = ['created_at', 'updated_at'];
    protected $modelsPath = 'app/Models';
    protected $syncDataPath;
    protected $excludedModels = [
        'PasswordReset',
        'OauthAccessToken', 
        'OauthRefreshToken',
        'role_user',
        'sms_gateway',
        'Server'
    ];

    public function __construct()
    {
        $this->syncDataPath = storage_path('app/sync_data');
        $this->ensureSyncDirectoryExists();
        $this->initializeDatabaseConnection();
        $this->log('info', "SyncService initialized");
    }

    /**
     * Log with configurable level and channel
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        // Check if sync logging is enabled
        if (!config('app.sync_logging_enabled', true)) {
            return;
        }

        // Use sync channel if available, otherwise fallback to default
        $channel = config('logging.channels.sync') ? 'sync' : null;
        
        if ($channel) {
            Log::channel($channel)->$level($message, $context);
        } else {
            Log::$level($message, $context);
        }
    }

    /**
     * Main sync entry point
     */
    public function sync(): array
    {
        if (!$this->isOnline()) {
            $this->log('info', "Sync postponed - offline");
            return ['success' => false, 'message' => 'Offline - sync postponed'];
        }

        $this->log('info', "Sync started");
        
        $models = $this->discoverModels();
        $results = [
            'pull' => $this->safePullFromMaster($models),
            'push' => $this->safePushToMaster($models)
        ];

        $this->log('info', "Sync completed", ['results' => $results]);
        return $results;
    }

    /**
     * Discover all available models dynamically
     */
    public function discoverModels(): array
    {
        $models = [];
        $modelsDirectory = base_path($this->modelsPath);
        
        if (!File::exists($modelsDirectory)) {
            $this->log('error', "Models directory not found: {$modelsDirectory}");
            return $models;
        }

        // Check database connection first
        if (!$this->verifyDatabaseConnection()) {
            $this->log('error', "Database connection failed - cannot discover models");
            return $models;
        }

        $files = File::files($modelsDirectory);
        
        foreach ($files as $file) {
            $className = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            
            // Skip excluded models
            if (in_array($className, $this->excludedModels)) {
                continue;
            }

            $fullClassName = "App\\Models\\{$className}";
            
            try {
                // Check if class exists and is instantiable
                if (class_exists($fullClassName)) {
                    $reflection = new ReflectionClass($fullClassName);
                    
                    if ($reflection->isInstantiable() && $reflection->isSubclassOf('Illuminate\Database\Eloquent\Model')) {
                        $model = new $fullClassName();
                        $tableName = $model->getTable();
                        
                        // Check if table exists in database using a safer method
                        if ($this->tableExists($tableName)) {
                            $models[$tableName] = [
                                'class' => $fullClassName,
                                'model' => $model,
                                'table' => $tableName
                            ];
                            $this->log('info', "Discovered model: {$className} -> {$tableName}");
                        } else {
                            $this->log('warning', "Table not found for model {$className}: {$tableName}");
                        }
                    }
                }
            } catch (ReflectionException $e) {
                $this->log('warning', "Could not reflect on model {$className}: " . $e->getMessage());
            } catch (\Exception $e) {
                $this->log('warning', "Error processing model {$className}: " . $e->getMessage());
            }
        }

        $this->log('info', "Total models discovered: " . count($models));
        return $models;
    }

    /**
     * Ensure sync data directory exists
     */
    protected function ensureSyncDirectoryExists(): void
    {
        if (!File::exists($this->syncDataPath)) {
            File::makeDirectory($this->syncDataPath, 0755, true);
        }
    }

    /**
     * Get last sync time for a specific model
     */
    public function getLastSyncTime(string $tableName): string
    {
        $syncFile = "{$this->syncDataPath}/{$tableName}_last_sync.dat";
        
        if (!File::exists($syncFile)) {
            return $this->defaultStartDate;
        }

        try {
            $content = trim(File::get($syncFile));
            return $this->validateDate($content) ?? $this->defaultStartDate;
        } catch (\Exception $e) {
            $this->log('error', "Failed reading sync time for {$tableName}: " . $e->getMessage());
            return $this->defaultStartDate;
        }
    }

    /**
     * Update last sync time for a specific model
     */
    public function updateLastSyncTime(string $tableName): bool
    {
        try {
            $syncFile = "{$this->syncDataPath}/{$tableName}_last_sync.dat";
            File::put($syncFile, now()->toDateTimeString());
            return true;
        } catch (\Exception $e) {
            $this->log('error', "Failed updating sync time for {$tableName}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Bulletproof pull implementation for all models
     */
    protected function safePullFromMaster(array $models): array
    {
        $results = [];
        $totalProcessed = 0;

        foreach ($models as $tableName => $modelInfo) {
            try {
                $result = $this->pullModelFromMaster($tableName, $modelInfo);
                $results[$tableName] = $result;
                
                if ($result['success']) {
                    $totalProcessed += $result['count'] ?? 0;
                }
            } catch (\Exception $e) {
                $this->log('error', "Pull failed for {$tableName}: " . $e->getMessage());
                $results[$tableName] = [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'count' => 0
                ];
            }
        }

        return [
            'success' => true,
            'message' => "Pulled {$totalProcessed} total records across " . count($models) . " models",
            'count' => $totalProcessed,
            'details' => $results
        ];
    }

    /**
     * Pull data for a specific model from master
     */
    protected function pullModelFromMaster(string $tableName, array $modelInfo): array
    {
        try {
            $response = Http::timeout($this->syncTimeout)
                ->withHeaders(['Accept' => 'application/json'])
                ->get("{$this->syncUrl}/pull/{$tableName}");

            if (!$response->successful()) {
                throw new \Exception("Server responded with HTTP {$response->status()}");
            }

            $data = $response->json();
            if (!is_array($data)) {
                throw new \Exception("Invalid response format for {$tableName}");
            }

            $processed = 0;
            $modelClass = $modelInfo['class'];
            $lastSync = $this->getLastSyncTime($tableName);

            \DB::transaction(function () use ($data, $modelClass, $lastSync, &$processed) {
                foreach ($data as $row) {
                    if ($this->safeUpsertModel($modelClass, $row, $lastSync)) {
                        $processed++;
                    }
                }
            });

            // $this->updateLastSyncTime($tableName);
            
            return [
                'success' => true,
                'message' => "Pulled {$processed} records for {$tableName}",
                'count' => $processed
            ];

        } catch (\Exception $e) {
            $this->log('error', "Pull failed for {$tableName}: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'count' => 0
            ];
        }
    }

    /**
     * Bulletproof push implementation for all models
     */
    protected function safePushToMaster(array $models): array
    {
        $results = [];
        $totalProcessed = 0;

        foreach ($models as $tableName => $modelInfo) {
            try {
                $result = $this->pushModelToMaster($tableName, $modelInfo);
                $results[$tableName] = $result;
                
                if ($result['success']) {
                    $totalProcessed += $result['count'] ?? 0;
                }
            } catch (\Exception $e) {
                $this->log('error', "Push failed for {$tableName}: " . $e->getMessage());
                $results[$tableName] = [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'count' => 0
                ];
            }
        }

        return [
            'success' => true,
            'message' => "Pushed {$totalProcessed} total records across " . count($models) . " models",
            'count' => $totalProcessed,
            'details' => $results
        ];
    }

    /**
     * Push data for a specific model to master
     */
    protected function pushModelToMaster(string $tableName, array $modelInfo): array
    {
        $changes = $this->getLocalChangesForModel($tableName, $modelInfo);
        
        if (empty($changes)) {
            return [
                'success' => true,
                'message' => "No changes to push for {$tableName}",
                'count' => 0
            ];
        }

        try {
            $response = Http::timeout($this->syncTimeout)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("{$this->syncUrl}/push/{$tableName}", $changes);

            if (!$response->successful()) {
                throw new \Exception("Server responded with HTTP {$response->status()}");
            }

            $this->updateLastSyncTime($tableName);
            
            return [
                'success' => true,
                'message' => "Pushed " . count($changes) . " records for {$tableName}",
                'count' => count($changes)
            ];

        } catch (\Exception $e) {
            $this->log('error', "Push failed for {$tableName}: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'count' => 0
            ];
        }
    }

    /**
     * Get local changes for a specific model
     */
    protected function getLocalChangesForModel(string $tableName, array $modelInfo): array
    {
        try {
            $modelClass = $modelInfo['class'];
            $lastSync = $this->getLastSyncTime($tableName);

            // Get records updated or created after last sync
            $changes = $modelClass::where(function ($query) use ($lastSync) {
                $query->where('updated_at', '>', $lastSync)
                      ->orWhere('created_at', '>', $lastSync);
            })
            ->get()
            ->map(function ($item) {
                $array = $item->toArray();
                
                // Process date fields
                foreach ($this->dateFields as $field) {
                    if (isset($array[$field])) {
                        $array[$field] = $this->validateDate($array[$field]);
                    }
                }
                
                return $array;
            })
            ->toArray();

            $this->log('info', "Changes found for {$tableName}: " . count($changes));
            return $changes;

        } catch (\Exception $e) {
            $this->log('error', "Failed getting changes for {$tableName}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Ultra-safe upsert for a specific model
     */
    public function safeUpsertModel(string $modelClass, array $row, string $lastSync): bool
    {
        try {
            // Validate required fields
            if (empty($row['id'])) {
                throw new \Exception("Missing ID for model {$modelClass}");
            }

            // Check if record is newer than last sync
            $recordDate = $this->validateDate($row['updated_at'] ?? $row['created_at'] ?? null);
            if ($recordDate && $recordDate <= $lastSync) {
                $this->log('debug', "Skipping record {$row['id']} - not newer than last sync");
                return false;
            }

            // Process dates
            foreach ($this->dateFields as $field) {
                if (isset($row[$field])) {
                    $row[$field] = $this->validateDate($row[$field]);
                    if ($row[$field] === null) {
                        unset($row[$field]);
                    }
                }
            }

            // Use Eloquent updateOrCreate
            $modelClass::updateOrCreate(
                ['id' => $row['id']],
                $row
            );

            return true;

        } catch (\Exception $e) {
            $this->log('warning', "Upsert failed for model {$modelClass}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Date validation that won't throw Carbon exceptions
     */
    protected function validateDate($date): ?string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d H:i:s');
        }

        if (empty($date)) {
            return null;
        }

        try {
            // First try strict format
            $parsed = Carbon::createFromFormat('Y-m-d H:i:s', $date);
            if ($parsed !== false) {
                return $parsed->toDateTimeString();
            }

            // Fallback to loose parsing
            $parsed = Carbon::parse($date);
            return $parsed->toDateTimeString();

        } catch (\Exception $e) {
            $this->log('debug', "Invalid date format: {$date}");
            return null;
        }
    }

    /**
     * Network check with multiple fallbacks
     */
    protected function isOnline(): bool
    {
        $testUrls = [
            'https://www.google.com',
            'https://www.cloudflare.com',
            'https://www.amazon.com'
        ];

        foreach ($testUrls as $url) {
            try {
                if (@fsockopen(parse_url($url, PHP_URL_HOST), 80, $errno, $errstr, 1)) {
                    return true;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return false;
    }

    /**
     * Initialize database connection with proper path
     */
    protected function initializeDatabaseConnection(): void
    {
        try {
            // Check if default database connection is SQLite
            $defaultConnection = config('database.default');
            if ($defaultConnection === 'sqlite') {
                $dbPath = config('database.connections.sqlite.database');
                
                // If the configured path doesn't exist, try to find it in public directory
                if (!File::exists($dbPath)) {
                    $publicDbPath = public_path('database.sqlite');
                    if (File::exists($publicDbPath)) {
                        $this->log('info', "Updating SQLite database path to: {$publicDbPath}");
                        config(['database.connections.sqlite.database' => $publicDbPath]);
                        
                        // Clear the database connection cache to force Laravel to use the new path
                        \DB::purge('sqlite');
                        \DB::reconnect('sqlite');
                    } else {
                        $this->log('warning', "SQLite database not found at configured path or public directory");
                    }
                }
            }
        } catch (\Exception $e) {
            $this->log('error', "Failed to initialize database connection: " . $e->getMessage());
        }
    }

    /**
     * Verify database connection and file existence
     */
    protected function verifyDatabaseConnection(): bool
    {
        try {
            // Check if we can connect to the database
            \DB::connection()->getPdo();
            return true;
        } catch (\Exception $e) {
            $this->log('error', "Database connection failed: " . $e->getMessage());
            
            // Try to identify the issue
            $config = config('database.connections.' . config('database.default'));
            if ($config && isset($config['database'])) {
                $dbPath = $config['database'];
                if (is_string($dbPath) && !File::exists($dbPath)) {
                    $this->log('error', "Database file not found at: {$dbPath}");
                    
                    // Check if it's in public directory (common Laravel setup)
                    $publicDbPath = public_path('database.sqlite');
                    if (File::exists($publicDbPath)) {
                        $this->log('info', "Found database in public directory: {$publicDbPath}");
                        // Update the database path in runtime config
                        config(['database.connections.sqlite.database' => $publicDbPath]);
                        return true;
                    }
                }
            }
            
            return false;
        }
    }

    /**
     * Check if a table exists using Eloquent ORM
     */
    protected function tableExists(string $tableName): bool
    {
        try {
            // Use Laravel's Schema facade to check if table exists
            return \Schema::hasTable($tableName);
        } catch (\Exception $e) {
            $this->log('warning', "Could not check if table {$tableName} exists: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get sync status for all models
     */
    public function getSyncStatus(): array
    {
        $models = $this->discoverModels();
        $status = [];

        foreach ($models as $tableName => $modelInfo) {
            $lastSync = $this->getLastSyncTime($tableName);
            $modelClass = $modelInfo['class'];
            
            try {
                $totalRecords = $modelClass::count();
                $recentRecords = $modelClass::where(function ($query) use ($lastSync) {
                    $query->where('updated_at', '>', $lastSync)
                          ->orWhere('created_at', '>', $lastSync);
                })->count();

                $status[$tableName] = [
                    'last_sync' => $lastSync,
                    'total_records' => $totalRecords,
                    'pending_sync' => $recentRecords,
                    'model_class' => $modelClass
                ];
            } catch (\Exception $e) {
                $status[$tableName] = [
                    'last_sync' => $lastSync,
                    'error' => $e->getMessage(),
                    'model_class' => $modelClass
                ];
            }
        }

        return $status;
    }

    /**
     * Reset sync data for a specific model or all models
     */
    public function resetSyncData(?string $tableName = null): bool
    {
        try {
            if ($tableName) {
                $syncFile = "{$this->syncDataPath}/{$tableName}_last_sync.dat";
                if (File::exists($syncFile)) {
                    File::delete($syncFile);
                }
                $this->log('info', "Reset sync data for {$tableName}");
            } else {
                // Reset all sync data
                $files = File::files($this->syncDataPath);
                foreach ($files as $file) {
                    if (Str::endsWith($file->getFilename(), '_last_sync.dat')) {
                        File::delete($file->getPathname());
                    }
                }
                $this->log('info', "Reset sync data for all models");
            }
            return true;
        } catch (\Exception $e) {
            $this->log('error', "Failed resetting sync data: " . $e->getMessage());
            return false;
        }
    }
}