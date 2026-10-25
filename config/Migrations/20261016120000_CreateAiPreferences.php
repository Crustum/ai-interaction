<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Create participant preference tables.
 */
class CreateAiPreferences extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('ai_preferences', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'uuid', ['null' => false])
            ->addColumn('participant_type', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('participant_id', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('pref_key', 'string', ['limit' => 191, 'null' => false])
            ->addColumn('value', 'text', ['null' => false])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['participant_type', 'participant_id', 'pref_key'], ['unique' => true])
            ->addIndex(['participant_type', 'participant_id'])
            ->create();
    }
}
