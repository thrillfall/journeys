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
            'name' => 'personal_settings#renderClusterVideo',
            'url' => '/personal_settings/render_cluster_video',
            'verb' => 'POST',
        ],
        [
            'name' => 'personal_settings#renderClusterVideoLandscape',
            'url' => '/personal_settings/render_cluster_video_landscape',
            'verb' => 'POST',
        ],
    ],
];
