<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curseforge_syncs', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('server_id')->unique();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();

            $table->string('status')->default('idle');
            $table->text('message')->nullable();
            $table->uuid('run_id')->nullable();
            $table->unsignedInteger('progress_done')->default(0);
            $table->unsignedInteger('progress_total')->default(0);

            // the modpack that was last analyzed
            $table->json('pack')->nullable();
            // classified modpack entries + the sync plan
            $table->json('entries')->nullable();
            // files on the server this plugin installed, keyed by entry key
            $table->json('managed')->nullable();
            // user decisions, entry key => include|exclude
            $table->json('overrides')->nullable();

            $table->boolean('auto_sync')->default(false);
            $table->boolean('auto_apply')->default(true);
            $table->string('watch_path')->nullable();
            $table->boolean('remove_unmanaged')->default(false);
            $table->boolean('apply_overrides')->default(true);
            $table->boolean('restart_after')->default(false);

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curseforge_syncs');
    }
};
