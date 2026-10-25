<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;
use Crustum\AiInteraction\Model\Entity\AiPreference;

/**
 * Participant preferences table.
 */
class AiPreferencesTable extends Table
{
    /**
     * Initializes the table.
     *
     * @param array<string, mixed> $config Table configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('ai_preferences');
        $this->setEntityClass(AiPreference::class);
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
    }

    /**
     * Builds default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator Validated validator.
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->uuid('id')
            ->allowEmptyString('id');

        $validator
            ->allowEmptyString('participant_type')
            ->allowEmptyString('participant_id');

        $validator
            ->requirePresence('pref_key', 'create')
            ->allowEmptyString('pref_key');

        $validator
            ->requirePresence('value', 'create')
            ->notEmptyString('value');

        return $validator;
    }
}
