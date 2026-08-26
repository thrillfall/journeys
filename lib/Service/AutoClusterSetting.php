<?php
namespace OCA\Journeys\Service;

use OCP\IConfig;

/**
 * Whether a user's photos get clustered into journeys by the nightly job.
 *
 * One place for the rule, because three callers need the same answer: the job
 * itself, the personal settings, and the diary app that asks the question.
 */
class AutoClusterSetting {

    private const KEY = 'autoCluster';

    public function __construct(
        private IConfig $config,
        private AlbumCreator $albumCreator,
    ) {}

    /** True once the user has said yes or no, so we stop asking. */
    public function isAnswered(string $userId): bool {
        return $this->raw($userId) !== '';
    }

    /**
     * An explicit answer decides. Without one, an account that has already used
     * the app keeps its nightly run — no existing install silently stops
     * clustering — while a fresh account waits for a yes, so nobody finds
     * albums of their scanned receipts before they were ever asked.
     */
    public function isEnabled(string $userId): bool {
        $answer = $this->raw($userId);
        if ($answer !== '') {
            return $answer === '1';
        }
        return $this->albumCreator->hasTrackedAlbums($userId)
            || $this->config->getUserKeys($userId, 'journeys') !== [];
    }

    public function setEnabled(string $userId, bool $enabled): void {
        $this->config->setUserValue($userId, 'journeys', self::KEY, $enabled ? '1' : '0');
    }

    private function raw(string $userId): string {
        return (string)$this->config->getUserValue($userId, 'journeys', self::KEY, '');
    }
}
