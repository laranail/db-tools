<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Example: migration using laranail/db-tools schema macros.
|------------------------------------------------------------------------------
| Pair with the example Order model. Demonstrates the auditColumns() and
| softDeletesWithUndo() macros (registered by DbToolsServiceProvider).
*/

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $t): void {
            $t->id();

            // Auto-managed identifiers (set by HasUuid + HasUlid traits at
            // creating time — see Order.php).
            $t->string('uuid')->unique();
            $t->string('ulid', 26)->unique();

            $t->string('reference')->unique();
            $t->decimal('amount', 12, 2);
            $t->string('status')->default('pending');

            // JSON columns — populated/read via HasJsonColumnAccessors.
            $t->json('metadata')->nullable();
            $t->json('audit')->nullable();

            // laranail/db-tools macros:
            $t->auditColumns();        // created_by, updated_by, deleted_by (foreignId, nullable, indexed)
            $t->softDeletesWithUndo(); // deleted_at + restored_at (timestamp, nullable, indexed)

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
