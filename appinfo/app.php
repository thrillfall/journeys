<?php
// This file is required by Nextcloud to register the app

namespace OCA\Journeys\AppInfo;

use OCP\AppFramework\App;

class Application extends App {
    public function __construct(array $urlParams = []) {
        parent::__construct('journeys', $urlParams);
    }
}
