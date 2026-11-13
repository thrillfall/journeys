<?php
namespace OCA\Journeys\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;

/**
 * Serves the app page (travel diaries, trips, videos), mounted at #content.
 * Reached via the sidebar navigation entry (info.xml -> <navigations>).
 */
class PageController extends Controller {

    public function __construct($appName, IRequest $request, private IEventDispatcher $eventDispatcher) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        // Viewer is optional; without it, videos open in Files instead.
        if (class_exists(\OCA\Viewer\Event\LoadViewer::class)) {
            $this->eventDispatcher->dispatchTyped(new \OCA\Viewer\Event\LoadViewer());
        }
        return new TemplateResponse('journeys', 'diary');
    }
}
