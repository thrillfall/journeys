<?php
namespace OCA\Journeys\Listener;

use OCA\Journeys\Service\JournalService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * When a Nextcloud user is deleted, purge their diary footprint: owned journals
 * (cascade), photos they contributed to any journal, and membership rows naming
 * them — otherwise those rows linger and reference now-orphaned storage.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {

    public function __construct(
        private JournalService $journalService,
        private LoggerInterface $logger,
    ) {}

    public function handle(Event $event): void {
        if (!$event instanceof UserDeletedEvent) {
            return;
        }
        $uid = $event->getUser()->getUID();
        try {
            $this->journalService->purgeUser($uid);
        } catch (\Throwable $e) {
            $this->logger->warning('Journeys: failed to purge diary data for deleted user', [
                'app' => 'journeys', 'uid' => $uid, 'exception' => $e->getMessage(),
            ]);
        }
    }
}
