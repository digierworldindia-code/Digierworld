<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Gap-free, concurrency-safe document numbering (RFQ-2026-000001 ...).
 */
class CreateNumberSequences extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'seq_key'    => ['type' => 'VARCHAR', 'constraint' => 40],
            'seq_year'   => ['type' => 'SMALLINT', 'unsigned' => true],
            'last_value' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
        ]);
        $this->forge->addPrimaryKey(['seq_key', 'seq_year']);
        $this->forge->createTable('number_sequences', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('number_sequences', true);
    }
}
