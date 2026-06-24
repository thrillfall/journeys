<?php
namespace OCA\Journeys\AppInfo;

use OCA\Journeys\Listener\UserDeletedListener;
use OCA\Journeys\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'journeys';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Register the notifier service so notifications created by this app are rendered
        $context->registerNotifierService(Notifier::class);
        // Clean up a user's diary data when their account is deleted.
        $context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
    }

    public function boot(IBootContext $context): void {
        // Nothing to boot
    }
}
