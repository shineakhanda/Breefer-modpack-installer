<?php

/*
 * Restores servers to their original egg once the modpack runner install finishes.
 */
\Breefer\ModpackInstaller\Support\EggSwap::listen();

// Switch to the Java version the installed pack needs (e.g. Java 21 for Minecraft 1.21.1).
\Breefer\ModpackInstaller\Support\EggSwap::$afterRestore = static function (\App\Models\Server $server): void {
    \Breefer\ModpackInstaller\JavaImage::apply($server);
};
