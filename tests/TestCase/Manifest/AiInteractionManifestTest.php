<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Test\TestCase\Manifest;

use Cake\TestSuite\TestCase;
use Crustum\AiInteraction\AiInteractionPlugin;
use Crustum\AiInteraction\Manifest\AiInteractionManifest;
use Crustum\PluginManifest\Manifest\ManifestResolver;

/**
 * Covers the standalone manifest provider: identical operations to the
 * former plugin-class manifest, discoverable without the interface.
 */
class AiInteractionManifestTest extends TestCase
{
    /**
     * The provider emits migrations and star operations.
     *
     * @return void
     */
    public function testManifestOperations(): void
    {
        $ops = AiInteractionManifest::manifest();
        $tags = array_column($ops, 'tag');

        $this->assertContains('migrations', $tags);
        $this->assertContains('star-repo', $tags);
    }

    /**
     * The resolver finds the provider for the plugin class.
     *
     * @return void
     */
    public function testResolverFindsProvider(): void
    {
        $this->assertSame(
            AiInteractionManifest::class,
            ManifestResolver::providerClass(AiInteractionPlugin::class),
        );
        $this->assertSame(AiInteractionManifest::manifest(), ManifestResolver::manifestFor(AiInteractionPlugin::class));
    }

    /**
     * The plugin class itself carries no manifest contract.
     *
     * @return void
     */
    public function testPluginClassHasNoManifestContract(): void
    {
        $this->assertFalse(method_exists(AiInteractionPlugin::class, 'manifest'));
    }
}
