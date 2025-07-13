<?php
namespace OCA\Journeys\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class PersonalSettingsController extends Controller {
    private $userSession;
    private $clusteringManager;

    public function __construct($appName, IRequest $request, IUserSession $userSession, 
        \OCA\Journeys\Service\ClusteringManager $clusteringManager
    ) {
        parent::__construct($appName, $request);
        $this->userSession = $userSession;
        $this->clusteringManager = $clusteringManager;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function startClustering() {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'No user'], 401);
        }
        $userId = $user->getUID();
        $result = $this->clusteringManager->clusterForUser($userId);
        return new JSONResponse($result);
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
