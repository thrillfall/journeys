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
    ],
];
