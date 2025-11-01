<?php
namespace OCA\Journeys\BackgroundJob;

use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

class RenderClusterVideoJob extends QueuedJob {
    protected function run($argument): void {
        $logger = null;
        try {
            /** @var LoggerInterface $logger */
            $logger = \OC::$server->get(LoggerInterface::class);
        } catch (\Throwable $e) {
            // ignore
        }

        try {
            if (!is_array($argument)) {
                throw new \InvalidArgumentException('Invalid job payload');
            }
            $userId = isset($argument['userId']) ? (string)$argument['userId'] : '';
            $albumId = isset($argument['albumId']) ? (int)$argument['albumId'] : 0;
            $orientation = isset($argument['orientation']) ? (string)$argument['orientation'] : 'portrait';
            if ($userId === '' || $albumId <= 0) {
                throw new \InvalidArgumentException('Missing userId or albumId');
            }

            /** @var \OCA\Journeys\Service\ClusterVideoJobRunner $runner */
            $runner = \OC::$server->get(\OCA\Journeys\Service\ClusterVideoJobRunner::class);

            if ($orientation === 'landscape') {
                $runner->renderForAlbumLandscape($userId, $albumId);
            } else {
                $runner->renderForAlbum($userId, $albumId);
            }
        } catch (\Throwable $e) {
            if ($logger) {
                try {
                    $logger->warning('Journeys: RenderClusterVideoJob failed', [
                        'exception' => $e->getMessage(),
                    ]);
                } catch (\Throwable $ignored) {}
            }
        }
    }
}
