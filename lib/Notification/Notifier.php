<?php
namespace OCA\Journeys\Notification;

use OCP\IL10N;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
    public function __construct(
        private readonly IL10N $l10n,
        private readonly IManager $manager,
    ) {}

    public function getID(): string {
        return 'journeys';
    }

    public function getName(): string {
        return $this->l10n->t('Journeys');
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== 'journeys') {
            // Not our app
            throw new UnknownNotificationException('app');
        }
        $subject = (string)$notification->getSubject();
        $params = (array)$notification->getSubjectParameters();
        switch ($subject) {
            case 'new_cluster':
                $album = $params['album'] ?? '';
                $start = $params['start'] ?? '';
                $end = $params['end'] ?? '';
                $count = $params['count'] ?? '';
                $location = $params['location'] ?? '';
                $notification
                    ->setParsedSubject($this->l10n->t('New Journey created'))
                    ->setParsedMessage(
                        $location
                            ? $this->l10n->t('%1$s (%2$s – %3$s): %4$s photos • %5$s', [$album, $start, $end, $count, $location])
                            : $this->l10n->t('%1$s (%2$s – %3$s): %4$s photos', [$album, $start, $end, $count])
                    );
                break;
            case 'clusters_summary':
                $total = (int)($params['count'] ?? 0);
                $first = (string)($params['first'] ?? '');
                $list = $params['list'] ?? [];
                if (!is_array($list)) { $list = []; }
                $notification
                    ->setParsedSubject($this->l10n->t('Journeys: new albums created'))
                    ->setParsedMessage($this->formatSummaryMessage($total, $list, $first));
                break;
            default:
                // Keep any pre-parsed content if present, else set generic fallback
                if ($notification->getParsedSubject() === null) {
                    $notification->setParsedSubject($this->l10n->t('Journeys'));
                }
                if ($notification->getParsedMessage() === null) {
                    $notification->setParsedMessage($this->l10n->t('A new journey album was created.'));
                }
        }
        return $notification;
    }

    private function formatSummaryMessage(int $total, array $list, string $fallbackFirst): string {
        $shown = array_slice($list, 0, 5);
        if (empty($shown) && $fallbackFirst !== '') {
            $shown = [$fallbackFirst];
        }
        $base = $this->l10n->t('%n new journey album created', ['count' => $total]);
        if ($total !== 1) {
            $base = $this->l10n->t('%n new journey albums created', ['count' => $total]);
        }
        if (!empty($shown)) {
            $extra = '';
            if ($total > count($shown)) {
                $extra = $this->l10n->t(' + %n more', ['count' => $total - count($shown)]);
            }
            return $base . ': ' . implode(', ', $shown) . $extra;
        }
        return $base;
    }
}
