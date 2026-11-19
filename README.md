# Breefer Modpack Installer

Browse and install complete modpacks from Modrinth (.mrpack), CurseForge and Feed The Beast. The right loader (Fabric/Quilt/Forge/NeoForge) and Minecraft version are installed via MCJars, mods are downloaded (client-only files skipped), overrides applied, worlds kept unless you choose a fresh install.

## Download

The ready-to-install package is [`release/breefer-modpack-installer-v1.2.0.rext`](release/breefer-modpack-installer-v1.2.0.rext) (also attached to the GitHub release). Upload it under **Admin → Extensions** in Reviactyl.

## Repository layout

```
extension.json      manifest (routes, slots, boot hook, frontend entries)
backend/            PHP: routes, controllers, boot hook, admin settings
frontend/src/       TypeScript/React source
frontend/dist/      compiled modules shipped in the .rext
release/            installable .rext package
```

## Requirements

- Reviactyl panel with the extension API `RCYL_v26` (tested against the `canary` target).
- Built and packaged with the official Reviactyl extension compiler.

## Install

**Admin area:** upload `breefer-modpack-installer.rext` under **Admin → Extensions** and enable it.

**Command line:** copy `breefer-modpack-installer.rext` into your Reviactyl root (e.g. `/var/www/reviactyl`), then:

```bash
cd /var/www/reviactyl
php artisan extensions:install breefer-modpack-installer --enable
php artisan optimize:clear
```

Older builds use the singular form shown in the example extension: `php artisan extension:install breefer-modpack-installer` then `php artisan extension:enable breefer-modpack-installer`.

## Usage

- Page: **Modpacks** (server sidebar)
- Permissions: `settings.reinstall`, `file.create`, `file.delete`

## Configuration

Admin area → sidebar group **Breefer** → **Modpack Installer** (root admins only). Fallback URL: `/admin/extensions/breefer-modpack-installer`.

Options: Enable/disable providers, CurseForge API key, automatic Java image, MCJars API URL, page visibility.

## Data and external services

- Data: Creates table `breefer_modpack_installer_jobs` and an internal nest **Breefer (internal)** with the egg **Breefer Modpack Runner** on first use.
- External services: Modrinth, CurseForge and FTB APIs (panel side, for browsing); the install container downloads the pack, mods and loader.

## Notes

- How it works: the server is switched to the runner egg, reinstalled, and automatically restored to its original egg/startup/image when the agent reports the install finished (panel `Installed` event — no queue worker needed). A **Restore** button exists in case an install gets stuck.
- After a Modrinth or CurseForge pack installs, the server switches to the egg image with the Java version its Minecraft version needs (1.20.5+ → Java 21, 1.18–1.20.4 → Java 17, 26.x → Java 25). For FTB packs check the Java version shown in the install dialog.
- A world created by a newer Minecraft version cannot be opened by an older modpack: choose a fresh install or move the old `world` folder away first.

## Development

```bash
php artisan d:extensions:dev breefer-modpack-installer --enable
php artisan d:extensions:watch breefer-modpack-installer
```

## Author

Shine Akhanda — part of the **Breefer** extension collection.

## License

MIT — see `LICENSE`.
