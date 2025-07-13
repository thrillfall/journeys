<?php
return [
    'routes' => [
        [
            'name' => 'personal_settings#startClustering',
            'url' => '/apps/journeys/personal_settings/start_clustering',
            'verb' => 'POST',
        ],
        [
            'name' => 'personal_settings#lastRun',
            'url' => '/apps/journeys/personal_settings/last_run',
            'verb' => 'GET',
        ],
    ],
];
