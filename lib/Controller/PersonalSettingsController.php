<?php
namespace OCA\Journeys\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IConfig;

class PersonalSettingsController extends Controller {
    private $userSession;
    private $clusteringManager;
    private $userConfig;

    public function __construct($appName, IRequest $request, IUserSession $userSession, 
        \OCA\Journeys\Service\ClusteringManager $clusteringManager,
        IConfig $userConfig
    ) {
        parent::__construct($appName, $request);
        $this->userSession = $userSession;
        $this->clusteringManager = $clusteringManager;
        $this->userConfig = $userConfig; // Now using IConfig
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
        $minClusterSize = (int)($this->request->getParam('minClusterSize') ?? 3);
        $maxTimeGap = (int)($this->request->getParam('maxTimeGap') ?? 86400);
        $maxDistanceKm = (float)($this->request->getParam('maxDistanceKm') ?? 100.0);
        // Persist settings for the user
        $this->userConfig->setUserValue($userId, 'journeys', 'minClusterSize', $minClusterSize);
        $this->userConfig->setUserValue($userId, 'journeys', 'maxTimeGap', $maxTimeGap);
        $this->userConfig->setUserValue($userId, 'journeys', 'maxDistanceKm', $maxDistanceKm);
        $result = $this->clusteringManager->clusterForUser($userId, $maxTimeGap, $maxDistanceKm, $minClusterSize);
        return new JSONResponse($result);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function saveClusteringSettings() {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'No user'], 401);
        }
        $userId = $user->getUID();
        $minClusterSize = (int)($this->request->getParam('minClusterSize') ?? 3);
        $maxTimeGap = (int)($this->request->getParam('maxTimeGap') ?? 86400);
        $maxDistanceKm = (float)($this->request->getParam('maxDistanceKm') ?? 100.0);
        try {
            $this->userConfig->setUserValue($userId, 'journeys', 'minClusterSize', $minClusterSize);
            $this->userConfig->setUserValue($userId, 'journeys', 'maxTimeGap', $maxTimeGap);
            $this->userConfig->setUserValue($userId, 'journeys', 'maxDistanceKm', $maxDistanceKm);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => 'Failed to save settings'], 500);
        }
    }

    public function getClusteringSettings() {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'No user'], 401);
        }
        $userId = $user->getUID();
        $minClusterSize = (int)($this->userConfig->getUserValue($userId, 'journeys', 'minClusterSize', 3));
        $maxTimeGap = (int)($this->userConfig->getUserValue($userId, 'journeys', 'maxTimeGap', 86400));
        $maxDistanceKm = (float)($this->userConfig->getUserValue($userId, 'journeys', 'maxDistanceKm', 100.0));
        return new JSONResponse([
            'minClusterSize' => $minClusterSize,
            'maxTimeGap' => $maxTimeGap,
            'maxDistanceKm' => $maxDistanceKm
        ]);
    }

    public function lastRun() {
        // TODO: Return the timestamp of the last clustering run
        return new JSONResponse(['lastRun' => null]);
    }
}
