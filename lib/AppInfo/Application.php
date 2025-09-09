<?php
namespace OCA\Journeys\AppInfo;

use OCA\Journeys\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'journeys';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Register the notifier service so notifications created by this app are rendered
        $context->registerNotifierService(Notifier::class);
    }

    public function boot(IBootContext $context): void {
        // Nothing to boot
    }
}
