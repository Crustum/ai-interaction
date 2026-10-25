<?php
declare(strict_types=1);

namespace Crustum\AiInteraction\Manifest;

use Crustum\AiInteraction\AiInteractionPlugin;
use Crustum\PluginManifest\Manifest\ManifestOps;

/**
 * Standalone manifest provider for the AiInteraction plugin.
 *
 * Plain class without interface or trait dependencies, so the plugin loads
 * and runs without `crustum/plugin-manifest` installed. Discovered by the
 * installer through the provider convention when the plugin class itself
 * provides no manifest. All package references stay inside method bodies,
 * which only execute when the installer (and therefore the package) exists.
 */
final class AiInteractionManifest
{
    /**
     * Plugin install assets via crustum/plugin-manifest.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function manifest(): array
    {
        $pluginPath = dirname(__DIR__, 2);
        $plugin = ManifestOps::pluginName(AiInteractionPlugin::class);

        return array_merge(
            ManifestOps::manifestMigrations(
                $plugin,
                $pluginPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'Migrations',
            ),
            ManifestOps::manifestStarRepo($plugin, 'Crustum/AiInteraction'),
        );
    }
}
