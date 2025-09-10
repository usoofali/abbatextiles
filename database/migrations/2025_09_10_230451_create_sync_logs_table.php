<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSyncLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('model_name'); // e.g., 'App\Models\Product'
            $table->unsignedBigInteger('model_id'); // ID of the record
            $table->enum('action', ['create', 'update', 'delete']); // Type of operation
            $table->enum('sync_status', ['pending', 'syncing', 'completed', 'failed'])->default('pending');
            $table->integer('sync_attempts')->default(0); // Number of sync attempts
            $table->timestamp('last_sync_attempt')->nullable(); // Last attempt timestamp
            $table->text('error_message')->nullable(); // Error message if sync failed
            $table->text('data')->nullable(); // JSON data of the record (stored as text)
            $table->timestamps();
            
            // Indexes for better performance
            $table->index(['model_name', 'sync_status']);
            $table->index(['model_name', 'model_id']);
            $table->index('sync_status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sync_logs');
    }
}
