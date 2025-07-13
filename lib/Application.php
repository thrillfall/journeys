<?php
namespace OCA\Journeys;

use OCP\AppFramework\App;

class Application extends App {
    public function __construct(array $urlParams = []) {
        parent::__construct('journeys', $urlParams);

        $container = $this->getContainer();

        // Register ClusteringManager and wire dependencies
        $container->registerService('ClusteringManager', function($c) {
            return new \OCA\Journeys\Service\ClusteringManager(
                $c->query('OCA\\Journeys\\Service\\ImageFetcher'),
                $c->query('OCA\\Journeys\\Service\\Clusterer'),
                $c->query('OCA\\Journeys\\Service\\AlbumCreator')
            );
        });
    }
}
