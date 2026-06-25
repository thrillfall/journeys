<?php
return [
    'routes' => [
        [
            'name' => 'personal_settings#startClustering',
            'url' => '/personal_settings/start_clustering',
            'verb' => 'POST',
        ],
        [
            'name' => 'personal_settings#lastRun',
            'url' => '/personal_settings/last_run',
            'verb' => 'GET',
        ],
        [
            'name' => 'personal_settings#getClusteringSettings',
            'url' => '/personal_settings/get_clustering_settings',
            'verb' => 'GET',
        ],
        [
            'name' => 'personal_settings#saveClusteringSettings',
            'url' => '/personal_settings/save_clustering_settings',
            'verb' => 'POST',
        ],
        [
            'name' => 'personal_settings#listClusters',
            'url' => '/personal_settings/clusters',
            'verb' => 'GET',
        ],
        [
            'name' => 'personal_settings#updateClusterName',
            'url' => '/personal_settings/update_cluster_name',
            'verb' => 'POST',
        ],
        [
            'name' => 'personal_settings#renderClusterVideo',
            'url' => '/personal_settings/render_cluster_video',
            'verb' => 'POST',
        ],
        [
            'name' => 'personal_settings#renderClusterVideoLandscape',
            'url' => '/personal_settings/render_cluster_video_landscape',
            'verb' => 'POST',
        ],
        [
            'name' => 'personal_settings#listRenderedVideos',
            'url' => '/personal_settings/rendered_videos',
            'verb' => 'GET',
        ],

        // --- Travel diary: main app page (Increment 2) ---
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // --- Travel diary (Increment 1) ---
        ['name' => 'diary#index', 'url' => '/diary/journals', 'verb' => 'GET'],
        ['name' => 'diary#create', 'url' => '/diary/journals', 'verb' => 'POST'],
        ['name' => 'diary#show', 'url' => '/diary/journals/{id}', 'verb' => 'GET'],
        ['name' => 'diary#update', 'url' => '/diary/journals/{id}', 'verb' => 'PUT'],
        ['name' => 'diary#destroy', 'url' => '/diary/journals/{id}', 'verb' => 'DELETE'],
        ['name' => 'diary#createEntry', 'url' => '/diary/journals/{id}/entries', 'verb' => 'POST'],
        ['name' => 'diary#updateEntry', 'url' => '/diary/entries/{entryId}', 'verb' => 'PUT'],
        ['name' => 'diary#destroyEntry', 'url' => '/diary/entries/{entryId}', 'verb' => 'DELETE'],
        ['name' => 'diary#setEntryPhotos', 'url' => '/diary/entries/{entryId}/photos', 'verb' => 'PUT'],
        ['name' => 'diary#dayPhotos', 'url' => '/diary/day-photos', 'verb' => 'GET'],
        ['name' => 'diary#libraryPhotos', 'url' => '/diary/library-photos', 'verb' => 'GET'],
        ['name' => 'diary#journalPhoto', 'url' => '/diary/journals/{id}/photo/{fileid}', 'verb' => 'GET'],
        ['name' => 'diary#share', 'url' => '/diary/journals/{id}/share', 'verb' => 'POST'],
        ['name' => 'diary#unshare', 'url' => '/diary/journals/{id}/unshare', 'verb' => 'POST'],

        // --- Travel diary: collaboration (Increment 5) ---
        ['name' => 'diary#sharees', 'url' => '/diary/sharees', 'verb' => 'GET'],
        ['name' => 'diary#members', 'url' => '/diary/journals/{id}/members', 'verb' => 'GET'],
        ['name' => 'diary#addMember', 'url' => '/diary/journals/{id}/members', 'verb' => 'POST'],
        ['name' => 'diary#removeMember', 'url' => '/diary/journals/{id}/members/{type}/{principal}', 'verb' => 'DELETE'],

        // --- Travel diary: public share page (Increment 3) ---
        ['name' => 'publicDiary#show', 'url' => '/s/{token}', 'verb' => 'GET'],
        ['name' => 'publicDiary#photo', 'url' => '/s/{token}/photo/{fileid}', 'verb' => 'GET'],
        ['name' => 'publicDiary#map', 'url' => '/s/{token}/map', 'verb' => 'GET'],
    ],
];
