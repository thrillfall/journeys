<?php
namespace OCA\Journeys\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IConfig;

use OCA\Journeys\Exception\ClusterNotFoundException;
use OCA\Journeys\Exception\NoImagesFoundException;
use OCA\Journeys\Service\AlbumCreator;
use OCA\Journeys\Service\ClusterVideoJobRunner;

class PersonalSettingsController extends Controller {
    private $userSession;
    private $clusteringManager;
    private $userConfig;
    private AlbumCreator $albumCreator;
    private ClusterVideoJobRunner $clusterVideoJobRunner;

    public function __construct($appName, IRequest $request, IUserSession $userSession, 
        \OCA\Journeys\Service\ClusteringManager $clusteringManager,
        IConfig $userConfig,
        AlbumCreator $albumCreator,
        ClusterVideoJobRunner $clusterVideoJobRunner,
    ) {
        parent::__construct($appName, $request);
        $this->userSession = $userSession;
        $this->clusteringManager = $clusteringManager;
        $this->userConfig = $userConfig; // Now using IConfig
        $this->albumCreator = $albumCreator;
        $this->clusterVideoJobRunner = $clusterVideoJobRunner;
    }

    /**
     * @NoAdminRequired
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
        $homeAware = filter_var($this->request->getParam('homeAwareEnabled') ?? false, FILTER_VALIDATE_BOOLEAN);
        $homeLat = $this->request->getParam('homeLat');
        $homeLon = $this->request->getParam('homeLon');
        $homeRadiusKm = $this->request->getParam('homeRadiusKm');
        $home = null;
        if ($homeAware && $homeLat !== null && $homeLon !== null) {
            $home = [
                'lat' => (float)$homeLat,
                'lon' => (float)$homeLon,
                'radiusKm' => $homeRadiusKm !== null ? (float)$homeRadiusKm : 50.0,
            ];
        }
        // Persist settings for the user
        $this->userConfig->setUserValue($userId, 'journeys', 'minClusterSize', $minClusterSize);
        $this->userConfig->setUserValue($userId, 'journeys', 'maxTimeGap', $maxTimeGap);
        $this->userConfig->setUserValue($userId, 'journeys', 'maxDistanceKm', $maxDistanceKm);
        $this->userConfig->setUserValue($userId, 'journeys', 'homeAwareEnabled', $homeAware ? '1' : '0');
        if ($homeLat !== null && $homeLon !== null) {
            $this->userConfig->setUserValue($userId, 'journeys', 'homeLat', (string)(float)$homeLat);
            $this->userConfig->setUserValue($userId, 'journeys', 'homeLon', (string)(float)$homeLon);
        }
        if ($homeRadiusKm !== null) {
            $this->userConfig->setUserValue($userId, 'journeys', 'homeRadiusKm', (string)(float)$homeRadiusKm);
        }
        $result = $this->clusteringManager->clusterForUser($userId, $maxTimeGap, $maxDistanceKm, $minClusterSize, $homeAware, $home, null);
        return new JSONResponse($result);
    }

    /**
     * @NoAdminRequired
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
        $homeAware = filter_var($this->request->getParam('homeAwareEnabled') ?? false, FILTER_VALIDATE_BOOLEAN);
        $homeLat = $this->request->getParam('homeLat');
        $homeLon = $this->request->getParam('homeLon');
        $homeRadiusKm = $this->request->getParam('homeRadiusKm');
        try {
            $this->userConfig->setUserValue($userId, 'journeys', 'minClusterSize', $minClusterSize);
            $this->userConfig->setUserValue($userId, 'journeys', 'maxTimeGap', $maxTimeGap);
            $this->userConfig->setUserValue($userId, 'journeys', 'maxDistanceKm', $maxDistanceKm);
            $this->userConfig->setUserValue($userId, 'journeys', 'homeAwareEnabled', $homeAware ? '1' : '0');
            if ($homeLat !== null && $homeLon !== null) {
                $this->userConfig->setUserValue($userId, 'journeys', 'homeLat', (string)(float)$homeLat);
                $this->userConfig->setUserValue($userId, 'journeys', 'homeLon', (string)(float)$homeLon);
            }
            if ($homeRadiusKm !== null) {
                $this->userConfig->setUserValue($userId, 'journeys', 'homeRadiusKm', (string)(float)$homeRadiusKm);
            }
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => 'Failed to save settings'], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function getClusteringSettings() {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'No user'], 401);
        }
        $userId = $user->getUID();
        $minClusterSize = (int)($this->userConfig->getUserValue($userId, 'journeys', 'minClusterSize', 3));
        $maxTimeGap = (int)($this->userConfig->getUserValue($userId, 'journeys', 'maxTimeGap', 86400));
        $maxDistanceKm = (float)($this->userConfig->getUserValue($userId, 'journeys', 'maxDistanceKm', 100.0));
        $homeAware = (bool)((int)$this->userConfig->getUserValue($userId, 'journeys', 'homeAwareEnabled', 0));
        $homeLat = $this->userConfig->getUserValue($userId, 'journeys', 'homeLat', null);
        $homeLon = $this->userConfig->getUserValue($userId, 'journeys', 'homeLon', null);
        $homeRadiusKm = $this->userConfig->getUserValue($userId, 'journeys', 'homeRadiusKm', 50.0);
        return new JSONResponse([
            'minClusterSize' => $minClusterSize,
            'maxTimeGap' => $maxTimeGap,
            'maxDistanceKm' => $maxDistanceKm,
            'homeAwareEnabled' => $homeAware,
            'homeLat' => $homeLat !== '' ? $homeLat : null,
            'homeLon' => $homeLon !== '' ? $homeLon : null,
            'homeRadiusKm' => (float)$homeRadiusKm,
        ]);
    }

    /**
     * @NoAdminRequired
     */
    public function lastRun() {
        // TODO: Return the timestamp of the last clustering run
        return new JSONResponse(['lastRun' => null]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function listClusters(): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'No user'], 401);
        }

        $userId = $user->getUID();
        $tracked = $this->albumCreator->getTrackedClusters($userId);

        $clusters = array_map(function (array $cluster) use ($userId) {
            $imageCount = 0;
            if (!empty($cluster['album_id'])) {
                $imageCount = count($this->albumCreator->getAlbumFileIdsForUser($userId, (int)$cluster['album_id']));
            }

            return [
                'id' => (int)$cluster['album_id'],
                'name' => $cluster['name'],
                'imageCount' => $imageCount,
                'location' => $cluster['location'],
                'dateRange' => [
                    'start' => $cluster['start_dt'],
                    'end' => $cluster['end_dt'],
                ],
            ];
        }, $tracked);

        return new JSONResponse(['clusters' => $clusters]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function renderClusterVideo(): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'No user'], 401);
        }

        $albumIdParam = $this->request->getParam('albumId');
        if ($albumIdParam === null || $albumIdParam === '') {
            return new JSONResponse(['error' => 'Missing albumId'], 400);
        }

        $albumId = (int)$albumIdParam;
        if ($albumId <= 0) {
            return new JSONResponse(['error' => 'Invalid albumId'], 400);
        }

        $userId = $user->getUID();
        $minGap = max(0, (int)($this->request->getParam('minGapSeconds') ?? 5));
        $duration = (float)($this->request->getParam('durationSeconds') ?? 2.5);
        $width = (int)($this->request->getParam('width') ?? 1920);
        $fps = (int)($this->request->getParam('fps') ?? 30);
        $maxImages = (int)($this->request->getParam('maxImages') ?? 80);

        try {
            $result = $this->clusterVideoJobRunner->renderForAlbum(
                $userId,
                $albumId,
                $minGap,
                $duration,
                $width,
                $fps,
                $maxImages > 0 ? $maxImages : 80,
            );
        } catch (NoImagesFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 404);
        } catch (ClusterNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => 'Failed to render video', 'detail' => $e->getMessage()], 500);
        }

        return new JSONResponse([
            'success' => true,
            'path' => $result['path'],
            'storedInUserFiles' => $result['storedInUserFiles'],
            'imageCount' => $result['imageCount'],
            'clusterName' => $result['clusterName'],
        ]);
    }
}
