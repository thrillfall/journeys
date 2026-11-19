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

    public function enqueueYearReview(string $userId, int $year, string $orientation = 'portrait'): void {
        $this->jobList->add(\OCA\Journeys\BackgroundJob\RenderYearReviewJob::class, [
            'userId' => $userId,
            'year' => $year,
            'orientation' => $orientation === 'landscape' ? 'landscape' : 'portrait',
        ]);
    }

    /**
     * Render jobs still waiting or running for this user. A finished job is
     * removed from the job list, so whatever is here has no video yet.
     *
     * Year-review jobs carry a reviewYear and no album.
     *
     * @return array<int, array{albumId:?int, reviewYear:?int, orientation:string}>
     */
    public function pendingForUser(string $userId): array {
        $out = [];
        try {
            foreach ([\OCA\Journeys\BackgroundJob\RenderClusterVideoJob::class, \OCA\Journeys\BackgroundJob\RenderYearReviewJob::class] as $class) {
                foreach ($this->jobList->getJobsIterator($class, null, 0) as $job) {
                    $arg = $job->getArgument();
                    if (!is_array($arg) || ($arg['userId'] ?? null) !== $userId) {
                        continue;
                    }
                    $out[] = [
                        'albumId' => isset($arg['albumId']) ? (int)$arg['albumId'] : null,
                        'reviewYear' => isset($arg['year']) ? (int)$arg['year'] : null,
                        'orientation' => ($arg['orientation'] ?? '') === 'landscape' ? 'landscape' : 'portrait',
                    ];
                }
            }
        } catch (\Throwable) {
            return [];
        }
        return $out;
    }
}
