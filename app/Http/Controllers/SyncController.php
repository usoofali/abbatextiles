<?php

namespace App\Http\Controllers;

use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SyncController extends Controller
{
    protected $syncService;

    public function __construct(SyncService $syncService)
    {
        $this->syncService = $syncService;
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
     * Pull data from master for a specific table
     * 
     * @param Request $request
     * @param string $tableName
     * @return JsonResponse
     */
    public function pull(Request $request, string $tableName): JsonResponse
    {
        try {
            $this->log('info', "Pull request received for table: {$tableName}");

            // Validate table name
            $validator = Validator::make(['table' => $tableName], [
                'table' => 'required|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid table name',
                    'errors' => $validator->errors()
                ], 400);
            }

            // Discover models to find the requested table
            $models = $this->syncService->discoverModels();
            
            if (!isset($models[$tableName])) {
                return response()->json([
                    'success' => false,
                    'message' => "Table '{$tableName}' not found or not available for sync"
                ], 404);
            }

            // Get the last sync time for this table
            $lastSync = $this->syncService->getLastSyncTime($tableName);
            
            // Get records that have been updated since last sync
            $modelClass = $models[$tableName]['class'];
            $records = $modelClass::where(function ($query) use ($lastSync) {
                $query->where('updated_at', '>', $lastSync)
                      ->orWhere('created_at', '>', $lastSync);
            })->get();

            $data = $records->map(function ($item) {
                $array = $item->toArray();
                
                // Process date fields to ensure consistent format
                foreach (['created_at', 'updated_at'] as $field) {
                    if (isset($array[$field])) {
                        $array[$field] = $item->$field ? $item->$field->toDateTimeString() : null;
                    }
                }
                
                return $array;
            })->toArray();

            $this->log('info', "Pull response for {$tableName}: " . count($data) . " records");

            return response()->json($data);

        } catch (\Exception $e) {
            $this->log('error', "Pull failed for {$tableName}: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Push data to master for a specific table
     * 
     * @param Request $request
     * @param string $tableName
     * @return JsonResponse
     */
    public function push(Request $request, string $tableName): JsonResponse
    {
        try {
            $this->log('info', "Push request received for table: {$tableName}");

            // Validate table name
            $validator = Validator::make(['table' => $tableName], [
                'table' => 'required|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid table name',
                    'errors' => $validator->errors()
                ], 400);
            }

            // Validate request data
            $data = $request->all();
            if (!is_array($data)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid data format. Expected array of records.'
                ], 400);
            }

            // Discover models to find the requested table
            $models = $this->syncService->discoverModels();
            
            if (!isset($models[$tableName])) {
                return response()->json([
                    'success' => false,
                    'message' => "Table '{$tableName}' not found or not available for sync"
                ], 404);
            }

            $modelClass = $models[$tableName]['class'];
            $processed = 0;
            $errors = [];

            // Process each record
            foreach ($data as $index => $record) {
                try {
                    // Validate record has required fields
                    if (empty($record['id'])) {
                        $errors[] = "Record at index {$index}: Missing ID field";
                        continue;
                    }

                    // Use the sync service's safe upsert method
                    if ($this->syncService->safeUpsertModel($modelClass, $record, $this->syncService->getLastSyncTime($tableName))) {
                        $processed++;
                    }
                } catch (\Exception $e) {
                    $errors[] = "Record at index {$index}: " . $e->getMessage();
                }
            }

            // Update last sync time if any records were processed
            if ($processed > 0) {
                $this->syncService->updateLastSyncTime($tableName);
            }

            $this->log('info', "Push completed for {$tableName}: {$processed} records processed, " . count($errors) . " errors");

            return response()->json([
                'success' => true,
                'message' => "Successfully processed {$processed} records",
                'processed' => $processed,
                'total_received' => count($data),
                'errors' => $errors
            ]);

        } catch (\Exception $e) {
            $this->log('error', "Push failed for {$tableName}: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get sync status for all tables
     * 
     * @return JsonResponse
     */
    public function status(): JsonResponse
    {
        try {
            $status = $this->syncService->getSyncStatus();
            
            return response()->json([
                'success' => true,
                'data' => $status
            ]);

        } catch (\Exception $e) {
            $this->log('error', "Status check failed: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get sync status for a specific table
     * 
     * @param string $tableName
     * @return JsonResponse
     */
    public function tableStatus(string $tableName): JsonResponse
    {
        try {
            $status = $this->syncService->getSyncStatus();
            
            if (!isset($status[$tableName])) {
                return response()->json([
                    'success' => false,
                    'message' => "Table '{$tableName}' not found"
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $status[$tableName]
            ]);

        } catch (\Exception $e) {
            $this->log('error', "Table status check failed for {$tableName}: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset sync data for a specific table or all tables
     * 
     * @param Request $request
     * @param string|null $tableName
     * @return JsonResponse
     */
    public function reset(Request $request, ?string $tableName = null): JsonResponse
    {
        try {
            $resetAll = $request->boolean('all', false);
            
            if ($resetAll) {
                $success = $this->syncService->resetSyncData();
                $message = 'Reset sync data for all tables';
            } else {
                $success = $this->syncService->resetSyncData($tableName);
                $message = $tableName ? "Reset sync data for table '{$tableName}'" : 'Reset sync data for all tables';
            }

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => $message
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to reset sync data'
                ], 500);
            }

        } catch (\Exception $e) {
            $this->log('error', "Reset sync data failed: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available tables for sync
     * 
     * @return JsonResponse
     */
    public function tables(): JsonResponse
    {
        try {
            $models = $this->syncService->discoverModels();
            
            $tables = [];
            foreach ($models as $tableName => $modelInfo) {
                $tables[] = [
                    'table' => $tableName,
                    'model_class' => $modelInfo['class']
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $tables,
                'count' => count($tables)
            ]);

        } catch (\Exception $e) {
            $this->log('error', "Get tables failed: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Perform full sync (pull and push for all tables)
     * 
     * @return JsonResponse
     */
    public function fullSync(): JsonResponse
    {
        try {
            $this->log('info', "Full sync request received");
            
            $result = $this->syncService->sync();
            
            return response()->json([
                'success' => true,
                'message' => 'Full sync completed',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            $this->log('error', "Full sync failed: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
