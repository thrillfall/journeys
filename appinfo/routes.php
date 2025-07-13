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
    ],
];
