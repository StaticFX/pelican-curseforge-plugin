<?php

return [
    // Only show the page for servers whose egg has one of these tags. Empty = every server.
    'egg_tags' => ['minecraft'],

    // Max size (KB) of a modpack zip uploaded through the panel.
    'max_upload_size' => (int) env('CURSEFORGE_SYNC_MAX_UPLOAD_KB', 512 * 1024),

    // Folder on the server that is checked for new modpack zips when auto sync is enabled.
    'default_watch_path' => env('CURSEFORGE_SYNC_WATCH_PATH', 'modpack'),

    // How often (minutes) the watch folders are checked.
    'watch_interval' => (int) env('CURSEFORGE_SYNC_WATCH_INTERVAL', 5),

    // Folder (relative to the server root) the plugin keeps its own files in. Removed mods are
    // moved to "<state_folder>/removed/<timestamp>/" instead of being deleted.
    'state_folder' => '.curseforge-sync',

    // Mods that are always treated as client-only. Matches the CurseForge project id or the
    // loader mod id (from mods.toml / fabric.mod.json).
    'client_only' => [
        // rendering / performance
        'sodium', 'embeddium', 'rubidium', 'iris', 'oculus', 'immediatelyfast', 'entityculling', 'sodiumextra',
        'sodiumoptionsapi', 'reeses_sodium_options', 'magnesium_extras', 'dynamic_fps', 'dynamicfps', 'enhancedblockentities',
        'continuity', 'cull_leaves', 'moreculling', 'betterfpsdist', 'fpsreducer', 'lambdynlights', 'dynamiclights',
        'sodiumdynamiclights', 'optimized_fullbright', 'fullbright',
        // ui / hud / tooltips
        'mousetweaks', 'controlling', 'enchdesc', 'legendarytooltips', 'equipmentcompare', 'iteminfo', 'itemborders',
        'toastcontrol', 'betteradvancements', 'advancementplaques', 'betterf3', 'catalogue', 'modmenu', 'appleskin',
        'jeresources', 'neat', 'torohealth', 'chat_heads', 'chatheads', 'tipsmod', 'yungsmenutweaks',
        'fancymenu', 'drippyloadingscreen', 'loadmyresources', 'packmenu', 'custommainmenu', 'smoothboot', 'rrls',
        'distraction_free_recipes', 'emiffect', 'remi', 'jeiintegration', 'craftpresence', 'simple_discord_rpc',
        // camera / zoom / visuals / sound
        'ok_zoomer', 'zoomify', 'freecam', 'notenoughanimations', 'firstperson', 'firstpersonmod', 'skinlayers3d',
        'waveycapes', 'falling_leaves', 'fallingleaves', 'visuality', 'particlerain', 'presencefootsteps',
        'sound_physics_remastered', 'ambientsounds', 'auudio', 'soundfilters', 'ding', 'blur', 'make_bubbles_pop',
        'lootbeams', 'physicsmod', 'cherishedworlds', 'reauth', 'screenshot_to_clipboard', 'entity_model_features',
        'entity_texture_features', 'euphoria_patcher', 'rainbows',
        // maps (client part)
        'xaerominimap', 'xaeroworldmap', 'xaerominimapfair',
    ],

    // Mods that are always installed on the server, even if they look client-only.
    'server_required' => [],

    // Paths inside the modpack's "overrides" folder that are never copied to the server.
    // Entries ending with "/" match folders.
    'client_only_paths' => [
        'mods/', // handled separately, every jar gets classified
        'resourcepacks/',
        'shaderpacks/',
        'screenshots/',
        'saves/',
        'logs/',
        'crash-reports/',
        'local/',
        'options.txt',
        'optionsof.txt',
        'optionsshaders.txt',
        'servers.dat',
        'servers.dat_old',
        'usernamecache.json',
        'usercache.json',
        'config/spark/tmp/',
    ],
];
