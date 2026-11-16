<?php

return [
    // Disabled until the privileged builder and shared-database QA have passed.
    'shared' => [
        'enabled'=>(bool)env('VENDRA_SHARED_ENABLED',false),
        'host'=>env('VENDRA_SHARED_HOST','127.0.0.1'),
        'port'=>(int)env('VENDRA_SHARED_PORT',3306),
        'database'=>env('VENDRA_SHARED_DATABASE','vendra_shared_live'),
        'manifest_hash'=>env('VENDRA_SHARED_SCHEMA_HASH',''),
    ],
    // Contains privileged credentials; root-only, outside web tree, never in .env/Git.
    'controlplane_file'=>'/var/lib/vendra/controlplane/shared.json',
];
