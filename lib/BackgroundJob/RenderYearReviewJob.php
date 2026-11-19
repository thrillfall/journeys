<?php
namespace OCA\Journeys\BackgroundJob;

use OCA\Journeys\Service\ClusterVideoJobRunner;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

class RenderYearReviewJob extends QueuedJob {
    protected function run($argument): void {
        $userId = is_array($argument) ? (string)($argument['userId'] ?? '') : '';
        $year = is_array($argument) ? (int)($argument['year'] ?? 0) : 0;
        $orientation = is_array($argument) && ($argument['orientation'] ?? '') === 'landscape' ? 'landscape' : 'portrait';
        try {
            if ($userId === '' || $year <= 0) {
                throw new \InvalidArgumentException('Missing userId or year');
            }
            \OC::$server->get(ClusterVideoJobRunner::class)->renderYearReview($userId, $year, $orientation);
        } catch (\Throwable $e) {
            try {
                \OC::$server->get(LoggerInterface::class)->warning('Journeys: RenderYearReviewJob failed', [
                    'userId' => $userId,
                    'year' => $year,
                    'exception' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
            }
        }
    }
}
