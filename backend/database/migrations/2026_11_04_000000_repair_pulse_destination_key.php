<?php

use App\Support\SchemaRepair;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SchemaRepair::foreign('performance_pulse_destination', 'notification_destination_id', 'notification_destinations', 'pulse_dest_notif_fk');
    }

    public function down(): void
    {
        // A repaired integrity constraint must survive an application rollback.
    }
};
