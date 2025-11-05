<?php
namespace OCA\Journeys\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use OCA\Journeys\Service\ClusteringManager;
use OCP\IConfig;

class DailyClusteringJob extends TimedJob {
    private IUserManager $userManager;
    private ClusteringManager $clusteringManager;
    private LoggerInterface $logger;
    private IConfig $config;

    public function __construct(ITimeFactory $time, IUserManager $userManager, ClusteringManager $clusteringManager, LoggerInterface $logger, IConfig $config) {
        parent::__construct($time);
        // Run once per 24 hours
        $this->setInterval(24 * 60 * 60);
        $this->userManager = $userManager;
        $this->clusteringManager = $clusteringManager;
        $this->logger = $logger;
        $this->config = $config;
    }

    protected function run($argument) {
        // Iterate over enabled users
        $users = $this->userManager->search('');
        foreach ($users as $user) {
            try {
                $uid = method_exists($user, 'getUID') ? $user->getUID() : (string)$user->getUID();
                // Read user-configured settings (fall back to UI defaults if not set)
                $minClusterSize = (int)$this->config->getUserValue($uid, 'journeys', 'minClusterSize', 3);
                $maxTimeGap = (int)$this->config->getUserValue($uid, 'journeys', 'maxTimeGap', 86400);
                $maxDistanceKm = (float)$this->config->getUserValue($uid, 'journeys', 'maxDistanceKm', 100.0);
                $includeGroupFolders = (bool)((int)$this->config->getUserValue($uid, 'journeys', 'includeGroupFolders', 0));
                // Home-aware by default; let ClusteringManager detect home automatically per user
                $result = $this->clusteringManager->clusterForUser($uid, $maxTimeGap, $maxDistanceKm, max(1, $minClusterSize), true, null, null, false, 5, true, $includeGroupFolders);
                if (isset($result['error'])) {
                    $this->logger->warning('Journeys daily job: clustering error', [ 'user' => $uid, 'error' => $result['error'] ]);
                } else {
                    $this->logger->info('Journeys daily job: clusters processed', [ 'user' => $uid, 'created' => $result['clustersCreated'] ]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Journeys daily job failed for user', [ 'user' => isset($uid) ? $uid : 'unknown', 'exception' => $e->getMessage() ]);
            }
        }
    }
}
