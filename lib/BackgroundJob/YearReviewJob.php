<?php
namespace OCA\Journeys\BackgroundJob;

use OCA\Journeys\Service\VideoRenderJobScheduler;
use OCA\Journeys\Service\YearReviewSchedule;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Queues the year-in-review for every user who lets Journeys create videos
 * automatically. The render itself runs as RenderYearReviewJob.
 */
class YearReviewJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private IUserManager $userManager,
        private IConfig $config,
        private VideoRenderJobScheduler $scheduler,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(6 * 60 * 60);
    }

    protected function run($argument): void {
        $today = $this->time->getDateTime();
        if (YearReviewSchedule::dueYear($today, null) === null) {
            return;
        }
        $this->userManager->callForSeenUsers(function (IUser $user) use ($today): void {
            $uid = $user->getUID();
            try {
                if (!(bool)((int)$this->config->getUserValue($uid, 'journeys', 'autoGenerateVideos', 0))) {
                    return;
                }
                $last = $this->config->getUserValue($uid, 'journeys', 'yearReviewQueued', '');
                $year = YearReviewSchedule::dueYear($today, $last === '' ? null : (int)$last);
                if ($year === null) {
                    return;
                }
                $orientation = $this->config->getUserValue($uid, 'journeys', 'videoOrientation', 'portrait') === 'landscape' ? 'landscape' : 'portrait';
                $this->scheduler->enqueueYearReview($uid, $year, $orientation);
                $this->config->setUserValue($uid, 'journeys', 'yearReviewQueued', (string)$year);
                $this->logger->info('Journeys: year in review queued', ['user' => $uid, 'year' => $year]);
            } catch (\Throwable $e) {
                $this->logger->warning('Journeys: could not queue year in review', ['user' => $uid, 'exception' => $e->getMessage()]);
            }
        });
    }
}
