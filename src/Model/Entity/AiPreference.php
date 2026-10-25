<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Model\Entity;

use Cake\ORM\Entity;

/**
 * Participant preference row.
 *
 * @property string $id Row identifier.
 * @property string|null $participant_type Participant type owning the preference.
 * @property string|null $participant_id Participant identifier owning the preference.
 * @property string $pref_key Preference key.
 * @property string $value JSON-encoded preference value.
 * @property \Cake\I18n\DateTime|null $created Creation timestamp.
 * @property \Cake\I18n\DateTime|null $modified Modification timestamp.
 */
class AiPreference extends Entity
{
    /**
     * Mass-assignable fields.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'id' => false,
        'participant_type' => true,
        'participant_id' => true,
        'pref_key' => true,
        'value' => true,
    ];
}
