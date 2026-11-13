<?php
namespace OCA\Journeys\Service;

use OCP\BackgroundJob\IJobList;

class VideoRenderJobScheduler {
    private IJobList $jobList;

    public function __construct(IJobList $jobList) {
        $this->jobList = $jobList;
    }

    /**
     * Enqueue background job to render a cluster video.
     * @param string $userId
     * @param int $albumId
     * @param 'portrait'|'landscape' $orientation
     */
    public function enqueue(string $userId, int $albumId, string $orientation = 'portrait'): void {
        $this->jobList->add(\OCA\Journeys\BackgroundJob\RenderClusterVideoJob::class, [
            'userId' => $userId,
            'albumId' => (int)$albumId,
            'orientation' => $orientation === 'landscape' ? 'landscape' : 'portrait',
        ]);
    }

    /**
     * Render jobs still waiting or running for this user. A finished job is
     * removed from the job list, so whatever is here has no video yet.
     *
     * @return array<int, array{albumId:int, orientation:string}>
     */
    public function pendingForUser(string $userId): array {
        $out = [];
        try {
            foreach ($this->jobList->getJobsIterator(\OCA\Journeys\BackgroundJob\RenderClusterVideoJob::class, null, 0) as $job) {
                $arg = $job->getArgument();
                if (!is_array($arg) || ($arg['userId'] ?? null) !== $userId) {
                    continue;
                }
                $out[] = [
                    'albumId' => (int)($arg['albumId'] ?? 0),
                    'orientation' => ($arg['orientation'] ?? '') === 'landscape' ? 'landscape' : 'portrait',
                ];
            }
        } catch (\Throwable) {
            return [];
        }
        return $out;
    }
}
