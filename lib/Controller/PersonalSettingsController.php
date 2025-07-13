<?php
namespace OCA\Journeys\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class PersonalSettingsController extends Controller {
    private $userSession;

    public function __construct($appName, IRequest $request, IUserSession $userSession) {
        parent::__construct($appName, $request);
        $this->userSession = $userSession;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function startClustering() {
        $user = $this->userSession->getUser();
        // TODO: Trigger clustering logic for $user
        // For now, just return a fake lastRun timestamp
        return new JSONResponse(['lastRun' => date('c')]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function lastRun() {
        // TODO: Return the timestamp of the last clustering run
        return new JSONResponse(['lastRun' => null]);
    }
}
