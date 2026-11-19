<?php

namespace Breefer\ModpackInstaller;

use Breefer\ModpackInstaller\Support\RouteScope;

final class AdminFields
{
    public static function intro(): string
    {
        return 'Installing a modpack temporarily switches the server to the internal <b>Breefer Modpack Runner</b> egg (nest “Breefer (internal)”, created automatically), '
            .'runs a reinstall, and restores the original egg, startup and image as soon as the agent reports the install finished. No queue worker is required.';
    }

    /** @return array<int, array<string, mixed>> */
    public static function fields(): array
    {
        return [
            ['key' => 'enable_modrinth', 'label' => 'Enable Modrinth', 'type' => 'toggle', 'default' => '1', 'section' => 'Providers'],
            ['key' => 'enable_curseforge', 'label' => 'Enable CurseForge', 'type' => 'toggle', 'default' => '1', 'section' => 'Providers'],
            ['key' => 'curseforge_api_key', 'label' => 'CurseForge API key', 'type' => 'password', 'default' => '', 'section' => 'Providers',
                'help' => 'Required for CurseForge. Get one at <a style="color:#93c5fd" href="https://console.curseforge.com/" target="_blank" rel="noopener">console.curseforge.com</a>. It is passed to the install container as a secret variable and removed afterwards.'],
            ['key' => 'enable_ftb', 'label' => 'Enable Feed The Beast', 'type' => 'toggle', 'default' => '1', 'section' => 'Providers'],
            ['key' => 'auto_java', 'label' => 'Switch to the matching Java image after installing a pack', 'type' => 'toggle', 'default' => '1', 'section' => 'Advanced',
                'help' => 'Uses the docker images of the server egg whose tag contains the Java version (e.g. <code>java_21</code>). Minecraft 1.20.5+ needs Java 21, 1.18–1.20.4 Java 17.'],
            ['key' => 'mcjars_url', 'label' => 'MCJars API URL (loader installs)', 'type' => 'text', 'default' => 'https://versions.mcjars.app', 'section' => 'Advanced'],
            ...RouteScope::fields(),
        ];
    }

    public static function saved(): void
    {
        RouteScope::saved();
    }
}
