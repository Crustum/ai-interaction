<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\Unit\Storage;

use Crustum\AiInteraction\Storage\StoredDocument;
use PHPUnit\Framework\TestCase;
use function strlen;

/**
 * Unit coverage for stored document values.
 */
final class StoredDocumentTest extends TestCase
{
    public function testSizeMeasuresRuntimeJsonRepresentation(): void
    {
        $document = new StoredDocument('known', ['value' => 'conversation'], ['project' => 'one']);

        self::assertSame('known', $document->key);
        self::assertSame(['value' => 'conversation'], $document->data);
        self::assertSame(['project' => 'one'], $document->metadata);
        self::assertSame(strlen('{"value":"conversation"}'), $document->size());
    }
}
