# CurseForge Modpack Sync

Drop a CurseForge modpack export onto a server and the plugin syncs the server's `mods` folder to it, leaving out client-only mods automatically.

No CurseForge API key is needed.

## How it works

1. **Get the modpack zip.** In the CurseForge app use *Export profile*. You get a zip with `manifest.json`, `modlist.html` and `overrides/`.
2. **Hand it to the server.** Either upload it on the *Modpack Sync* page, point the page at a zip already on the server, or turn on *Auto sync* and drop it into the watch folder (default `/modpack`) via SFTP or the file manager.
3. **Analysis.** Every file in the manifest is looked up on curseforge.com. For each mod jar the plugin reads only its metadata (`mods.toml` / `neoforge.mods.toml` / `fabric.mod.json` / `quilt.mod.json`) with HTTP range requests, a few KB per mod instead of the whole jar. Results are cached forever per file id, so re-analyzing a pack takes seconds.
4. **Review.** The page lists every mod with its side, why it was classified that way and what will happen: install, update, remove, keep, skip, manual. Any mod can be forced onto or off the server.
5. **Sync.** Mods are downloaded by the daemon straight from the CurseForge CDN. Replaced and removed jars are **moved** to `/.curseforge-sync/removed/<timestamp>/`, never deleted. Then the pack's overrides (configs, scripts, …) are copied over, minus client-only files like resource packs and `options.txt`.

### Client-only detection

The first signal that has an opinion wins:

| # | Signal | Example |
|---|--------|---------|
| 1 | Your own override on the page | "Keep on server" / "Exclude from server" |
| 2 | `client_only` / `server_required` lists in the config (CurseForge project id or loader mod id) | `sodium`, `xaerominimap` |
| 3 | Explicit jar metadata | `"environment": "client"` (Fabric/Quilt), `clientSideOnly=true`, every dependency `side="CLIENT"` (NeoForge/Forge) |
| 4 | The file's *Client* / *Server* tags on CurseForge | tagged *Client* only → excluded |
| 5 | Weak hints | `displayTest="IGNORE_ALL_VERSION"` |
| – | Nothing found | installed. A missing client mod costs nothing, but a missing content mod can delete blocks from the world |

Afterwards a dependency check puts back any excluded mod that a server-side mod requires, because a server won't start with a missing required dependency.

With the example pack (216 files, NeoForge 1.21.1) this excludes 22 mods: Sodium, ImmediatelyFast, Entity Culling, Xaero's maps, Mouse Tweaks, Controlling, EMI addons, sound / animation mods, and similar.

### Keeping track

The plugin remembers which jar it installed for which CurseForge project. When a new pack version swaps a mod's file, the old jar is moved away. When a mod is dropped from the pack, its jar is removed. Jars that were already in `mods/` and match the pack are adopted on the first sync. Any other jar ("Not part of the modpack") is left alone, unless you enable *Also remove jars that are not part of the modpack* when syncing.

### Loader & Minecraft version

The page compares the pack's Minecraft and loader version with the server's startup variables (`MC_VERSION` / `MINECRAFT_VERSION`, `NEOFORGE_VERSION`, `FORGE_VERSION`, `LOADER_VERSION`, …). If they differ, *Update startup variables* sets them. Reinstall the server afterwards so the new loader is installed.

## Requirements

- Pelican panel with a running **queue worker** (`php artisan queue:work`, the default `pelican-queue` service). Analysis and sync run as a chain of short jobs. On its first load after an install or update, the plugin signals the workers to restart (like `php artisan queue:restart`), so they pick up the plugin's job class.
- The panel's **scheduler** cron, only needed for auto sync.
- The panel must be able to reach `www.curseforge.com` and `mediafilez.forgecdn.net`; the nodes must be able to reach `mediafilez.forgecdn.net`.

## Configuration

`config/curseforge-sync.php` (env overrides in brackets):

- `egg_tags`: show the page for eggs with these tags. Eggs with a `MC_VERSION`/`MINECRAFT_VERSION`/`NEOFORGE_VERSION`/`FORGE_VERSION` variable are always recognized. Empty = all servers.
- `max_upload_size` (`CURSEFORGE_SYNC_MAX_UPLOAD_KB`): upload limit for the panel upload. PHP's `upload_max_filesize` / `post_max_size` still apply, so use the server-path option for huge packs.
- `default_watch_path` (`CURSEFORGE_SYNC_WATCH_PATH`), `watch_interval` (`CURSEFORGE_SYNC_WATCH_INTERVAL`, minutes)
- `client_only`, `server_required`: mod lists, by CurseForge project id or loader mod id.
- `client_only_paths`: override files/folders that never go to the server.

## Caveats

- The curseforge.com endpoints used here are the ones the website itself uses. They are not an official API and could change.
- Override files overwrite server files with the same name on every sync, so server-side edits to a pack-shipped config are lost. Turn off *Copy configs* in the sync dialog to skip overrides.
- Only CurseForge **client** exports are supported, not server packs (those already contain the right mods).
