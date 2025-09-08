<?php
namespace OCA\Journeys\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use OCA\Journeys\Service\ClusteringManager;

class DailyClusteringJob extends TimedJob {
    private IUserManager $userManager;
    private ClusteringManager $clusteringManager;
    private LoggerInterface $logger;

    public function __construct(ITimeFactory $time, IUserManager $userManager, ClusteringManager $clusteringManager, LoggerInterface $logger) {
        parent::__construct($time);
        // Run once per 24 hours
        $this->setInterval(24 * 60 * 60);
        $this->userManager = $userManager;
        $this->clusteringManager = $clusteringManager;
        $this->logger = $logger;
    }

    protected function run($argument) {
        // Iterate over enabled users
        $users = $this->userManager->search('');
        foreach ($users as $user) {
            try {
                $uid = method_exists($user, 'getUID') ? $user->getUID() : (string)$user->getUID();
                // Home-aware by default; let ClusteringManager detect home automatically per user
                $result = $this->clusteringManager->clusterForUser($uid, 24 * 3600, 50.0, 3, true, null, null, false, 5);
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
