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
        $maxTimeGapHours = (float)($this->request->getParam('maxTimeGap') ?? 24.0);
        $maxTimeGap = (int)round($maxTimeGapHours * 3600);
        $maxDistanceKm = (float)($this->request->getParam('maxDistanceKm') ?? 100.0);
        $homeAware = true;
        $homeLat = $this->request->getParam('homeLat');
        $homeLon = $this->request->getParam('homeLon');
        $homeRadiusKm = $this->request->getParam('homeRadiusKm');
        $home = null;
        $includeGroupFolders = filter_var($this->request->getParam('includeGroupFolders') ?? false, FILTER_VALIDATE_BOOLEAN);
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
        $this->userConfig->setUserValue($userId, 'journeys', 'includeGroupFolders', $includeGroupFolders ? '1' : '0');
        // Optional home-aware thresholds
        $nearTimeGapHours = (float)($this->request->getParam('nearTimeGap') ?? 6.0);
        $nearDistanceKm = (float)($this->request->getParam('nearDistanceKm') ?? 3.0);
        $awayTimeGapHours = (float)($this->request->getParam('awayTimeGap') ?? 36.0);
        $awayDistanceKm = (float)($this->request->getParam('awayDistanceKm') ?? 50.0);
        $nearTimeGap = (int)round($nearTimeGapHours * 3600);
        $awayTimeGap = (int)round($awayTimeGapHours * 3600);
        $this->userConfig->setUserValue($userId, 'journeys', 'nearTimeGap', $nearTimeGap);
        $this->userConfig->setUserValue($userId, 'journeys', 'nearDistanceKm', $nearDistanceKm);
        $this->userConfig->setUserValue($userId, 'journeys', 'awayTimeGap', $awayTimeGap);
        $this->userConfig->setUserValue($userId, 'journeys', 'awayDistanceKm', $awayDistanceKm);
        // home-aware is always enabled by default; no flag persisted
        $autoGenerateVideos = filter_var($this->request->getParam('autoGenerateVideos') ?? false, FILTER_VALIDATE_BOOLEAN);
        $videoOrientation = (string)($this->request->getParam('videoOrientation') ?? 'portrait');
        $videoOrientation = in_array($videoOrientation, ['portrait', 'landscape'], true) ? $videoOrientation : 'portrait';
        $this->userConfig->setUserValue($userId, 'journeys', 'autoGenerateVideos', $autoGenerateVideos ? '1' : '0');
        // New: includeMotionFromGCam toggle
        $includeMotionFromGCam = filter_var($this->request->getParam('includeMotionFromGCam') ?? true, FILTER_VALIDATE_BOOLEAN);
        $this->userConfig->setUserValue($userId, 'journeys', 'includeMotionFromGCam', $includeMotionFromGCam ? '1' : '0');
        // New: showVideoTitle toggle
        $showVideoTitle = filter_var($this->request->getParam('showVideoTitle') ?? true, FILTER_VALIDATE_BOOLEAN);
        $this->userConfig->setUserValue($userId, 'journeys', 'showVideoTitle', $showVideoTitle ? '1' : '0');
        // New: boostFaces toggle (prefer images with faces in video selection)
        $boostFaces = filter_var($this->request->getParam('boostFaces') ?? true, FILTER_VALIDATE_BOOLEAN);
        $this->userConfig->setUserValue($userId, 'journeys', 'boostFaces', $boostFaces ? '1' : '0');
        $this->userConfig->setUserValue($userId, 'journeys', 'videoOrientation', $videoOrientation);
        if ($homeLat !== null && $homeLon !== null) {
            $this->userConfig->setUserValue($userId, 'journeys', 'homeLat', (string)(float)$homeLat);
            $this->userConfig->setUserValue($userId, 'journeys', 'homeLon', (string)(float)$homeLon);
            // also persist combined JSON for compatibility with HomeService consumers
            try {
                $payload = json_encode([
                    'lat' => (float)$homeLat,
                    'lon' => (float)$homeLon,
                    'radiusKm' => $homeRadiusKm !== null ? (float)$homeRadiusKm : 50.0,
                ]);
                if ($payload !== false) {
                    $this->userConfig->setUserValue($userId, 'journeys', 'home', $payload);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        if ($homeRadiusKm !== null) {
            $this->userConfig->setUserValue($userId, 'journeys', 'homeRadiusKm', (string)(float)$homeRadiusKm);
        }
        $result = $this->clusteringManager->clusterForUser($userId, $maxTimeGap, $maxDistanceKm, $minClusterSize, $homeAware, $home, null, false, 2, false, $includeGroupFolders);
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
            $includeGroupFolders = filter_var($this->request->getParam('includeGroupFolders') ?? false, FILTER_VALIDATE_BOOLEAN);
            $this->userConfig->setUserValue($userId, 'journeys', 'includeGroupFolders', $includeGroupFolders ? '1' : '0');
            // Optional home-aware thresholds
            $nearTimeGapHours = (float)($this->request->getParam('nearTimeGap') ?? 6.0);
            $nearDistanceKm = (float)($this->request->getParam('nearDistanceKm') ?? 3.0);
            $awayTimeGapHours = (float)($this->request->getParam('awayTimeGap') ?? 36.0);
            $awayDistanceKm = (float)($this->request->getParam('awayDistanceKm') ?? 50.0);
            $nearTimeGap = (int)round($nearTimeGapHours * 3600);
            $awayTimeGap = (int)round($awayTimeGapHours * 3600);
            $this->userConfig->setUserValue($userId, 'journeys', 'nearTimeGap', $nearTimeGap);
            $this->userConfig->setUserValue($userId, 'journeys', 'nearDistanceKm', $nearDistanceKm);
            $this->userConfig->setUserValue($userId, 'journeys', 'awayTimeGap', $awayTimeGap);
            $this->userConfig->setUserValue($userId, 'journeys', 'awayDistanceKm', $awayDistanceKm);
            // home-aware is always enabled by default; no flag persisted
            $autoGenerateVideos = filter_var($this->request->getParam('autoGenerateVideos') ?? false, FILTER_VALIDATE_BOOLEAN);
            $videoOrientation = (string)($this->request->getParam('videoOrientation') ?? 'portrait');
            $videoOrientation = in_array($videoOrientation, ['portrait', 'landscape'], true) ? $videoOrientation : 'portrait';
            $this->userConfig->setUserValue($userId, 'journeys', 'autoGenerateVideos', $autoGenerateVideos ? '1' : '0');
            // New: includeMotionFromGCam toggle
            $includeMotionFromGCam = filter_var($this->request->getParam('includeMotionFromGCam') ?? true, FILTER_VALIDATE_BOOLEAN);
            $this->userConfig->setUserValue($userId, 'journeys', 'includeMotionFromGCam', $includeMotionFromGCam ? '1' : '0');
            // New: showVideoTitle toggle
            $showVideoTitle = filter_var($this->request->getParam('showVideoTitle') ?? true, FILTER_VALIDATE_BOOLEAN);
            $this->userConfig->setUserValue($userId, 'journeys', 'showVideoTitle', $showVideoTitle ? '1' : '0');
            // New: boostFaces toggle (prefer images with faces in video selection)
            $boostFaces = filter_var($this->request->getParam('boostFaces') ?? true, FILTER_VALIDATE_BOOLEAN);
            $this->userConfig->setUserValue($userId, 'journeys', 'boostFaces', $boostFaces ? '1' : '0');
            $this->userConfig->setUserValue($userId, 'journeys', 'videoOrientation', $videoOrientation);
            if ($homeLat !== null && $homeLon !== null) {
                $this->userConfig->setUserValue($userId, 'journeys', 'homeLat', (string)(float)$homeLat);
                $this->userConfig->setUserValue($userId, 'journeys', 'homeLon', (string)(float)$homeLon);
                // also persist combined JSON for compatibility with HomeService consumers
                try {
                    $payload = json_encode([
                        'lat' => (float)$homeLat,
                        'lon' => (float)$homeLon,
                        'radiusKm' => $homeRadiusKm !== null ? (float)$homeRadiusKm : 50.0,
                    ]);
                    if ($payload !== false) {
                        $this->userConfig->setUserValue($userId, 'journeys', 'home', $payload);
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
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
        $maxTimeGapSeconds = (int)($this->userConfig->getUserValue($userId, 'journeys', 'maxTimeGap', 86400));
        $maxTimeGap = (float)$maxTimeGapSeconds / 3600.0;
        $maxDistanceKm = (float)($this->userConfig->getUserValue($userId, 'journeys', 'maxDistanceKm', 100.0));
        $homeAware = true;
        $homeLat = $this->userConfig->getUserValue($userId, 'journeys', 'homeLat', null);
        $homeLon = $this->userConfig->getUserValue($userId, 'journeys', 'homeLon', null);
        $homeRadiusKm = $this->userConfig->getUserValue($userId, 'journeys', 'homeRadiusKm', 50.0);
        $nearTimeGapSeconds = (int)$this->userConfig->getUserValue($userId, 'journeys', 'nearTimeGap', 21600);
        $nearTimeGap = (float)$nearTimeGapSeconds / 3600.0;
        $nearDistanceKm = (float)$this->userConfig->getUserValue($userId, 'journeys', 'nearDistanceKm', 3.0);
        $awayTimeGapSeconds = (int)$this->userConfig->getUserValue($userId, 'journeys', 'awayTimeGap', 129600);
        $awayTimeGap = (float)$awayTimeGapSeconds / 3600.0;
        $awayDistanceKm = (float)$this->userConfig->getUserValue($userId, 'journeys', 'awayDistanceKm', 50.0);
        $homeName = null;
        $includeGroupFolders = (bool)((int)$this->userConfig->getUserValue($userId, 'journeys', 'includeGroupFolders', 0));
        // fallback to combined 'home' JSON if individual keys are not set
        if (($homeLat === null || $homeLat === '') || ($homeLon === null || $homeLon === '')) {
            try {
                $raw = $this->userConfig->getUserValue($userId, 'journeys', 'home', '');
                if (is_string($raw) && $raw !== '') {
                    $data = json_decode($raw, true);
                    if (is_array($data) && isset($data['lat'], $data['lon'])) {
                        $homeLat = (string)$data['lat'];
                        $homeLon = (string)$data['lon'];
                        if (!isset($homeRadiusKm) || $homeRadiusKm === null || $homeRadiusKm === '' || (float)$homeRadiusKm <= 0) {
                            $homeRadiusKm = isset($data['radiusKm']) ? (float)$data['radiusKm'] : 50.0;
                        }
                        if (isset($data['name']) && is_string($data['name']) && $data['name'] !== '') {
                            $homeName = $data['name'];
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        if ($homeName === null) {
            try {
                $raw2 = $this->userConfig->getUserValue($userId, 'journeys', 'home', '');
                if (is_string($raw2) && $raw2 !== '') {
                    $data2 = json_decode($raw2, true);
                    if (is_array($data2) && isset($data2['name']) && is_string($data2['name']) && $data2['name'] !== '') {
                        $homeName = $data2['name'];
                    }
                }
            } catch (\Throwable $e) {
            }
        }
        $autoGenerateVideos = (bool)((int)$this->userConfig->getUserValue($userId, 'journeys', 'autoGenerateVideos', 0));
        $includeMotionFromGCam = (bool)((int)$this->userConfig->getUserValue($userId, 'journeys', 'includeMotionFromGCam', 1));
        $showVideoTitle = (bool)((int)$this->userConfig->getUserValue($userId, 'journeys', 'showVideoTitle', 1));
        $boostFaces = (bool)((int)$this->userConfig->getUserValue($userId, 'journeys', 'boostFaces', 1));
        $videoOrientation = (string)$this->userConfig->getUserValue($userId, 'journeys', 'videoOrientation', 'portrait');
        return new JSONResponse([
            'minClusterSize' => $minClusterSize,
            'maxTimeGap' => $maxTimeGap,
            'maxDistanceKm' => $maxDistanceKm,
            'includeGroupFolders' => $includeGroupFolders,
            'homeAwareEnabled' => $homeAware,
            'homeLat' => $homeLat !== '' ? $homeLat : null,
            'homeLon' => $homeLon !== '' ? $homeLon : null,
            'homeRadiusKm' => (float)$homeRadiusKm,
            'homeName' => $homeName,
            'autoGenerateVideos' => $autoGenerateVideos,
            'includeMotionFromGCam' => $includeMotionFromGCam,
            'showVideoTitle' => $showVideoTitle,
            'boostFaces' => $boostFaces,
            'videoOrientation' => in_array($videoOrientation, ['portrait', 'landscape'], true) ? $videoOrientation : 'portrait',
            'nearTimeGap' => $nearTimeGap,
            'nearDistanceKm' => $nearDistanceKm,
            'awayTimeGap' => $awayTimeGap,
            'awayDistanceKm' => $awayDistanceKm,
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
        // Sort latest-first by end_dt (fallback start_dt, then id)
        usort($tracked, function(array $a, array $b) {
            $aEnd = isset($a['end_dt']) ? strtotime((string)$a['end_dt']) : 0;
            $bEnd = isset($b['end_dt']) ? strtotime((string)$b['end_dt']) : 0;
            if ($aEnd !== $bEnd) {
                return $bEnd <=> $aEnd;
            }
            $aStart = isset($a['start_dt']) ? strtotime((string)$a['start_dt']) : 0;
            $bStart = isset($b['start_dt']) ? strtotime((string)$b['start_dt']) : 0;
            if ($aStart !== $bStart) {
                return $bStart <=> $aStart;
            }
            return (int)($b['album_id'] ?? 0) <=> (int)($a['album_id'] ?? 0);
        });

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
                null,
                null,
                (bool)((int)$this->userConfig->getUserValue($userId, 'journeys', 'includeMotionFromGCam', 1)),
            );
        } catch (NoImagesFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 404);
        } catch (ClusterNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            $detail = $e->getMessage();
            if ($this->isFfmpegMissingError($detail)) {
                return new JSONResponse([
                    'error' => 'Video rendering is unavailable because ffmpeg is not installed on the server. Please ask your administrator to install ffmpeg and try again.',
                    'detail' => $detail,
                ], 500);
            }

            return new JSONResponse([
                'error' => 'Failed to render video',
                'detail' => $detail,
            ], 500);
        }

        return new JSONResponse([
            'success' => true,
            'path' => $result['path'],
            'storedInUserFiles' => $result['storedInUserFiles'],
            'imageCount' => $result['imageCount'],
            'clusterName' => $result['clusterName'],
        ]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function renderClusterVideoLandscape(): JSONResponse {
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
            $result = $this->clusterVideoJobRunner->renderForAlbumLandscape(
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
            $detail = $e->getMessage();
            if ($this->isFfmpegMissingError($detail)) {
                return new JSONResponse([
                    'error' => 'Video rendering is unavailable because ffmpeg is not installed on the server. Please ask your administrator to install ffmpeg and try again.',
                    'detail' => $detail,
                ], 500);
            }

            return new JSONResponse([
                'error' => 'Failed to render video',
                'detail' => $detail,
            ], 500);
        }

        return new JSONResponse([
            'success' => true,
            'path' => $result['path'],
            'storedInUserFiles' => $result['storedInUserFiles'],
            'imageCount' => $result['imageCount'],
            'clusterName' => $result['clusterName'],
        ]);
    }

    private function isFfmpegMissingError(string $message): bool {
        $normalized = strtolower($message);
        if (str_contains($normalized, 'ffmpeg failed') && str_contains($normalized, 'not found')) {
            return true;
        }

        if (str_contains($normalized, 'ffmpeg') && str_contains($normalized, 'no such file or directory')) {
            return true;
        }

        if (str_contains($normalized, 'unable to find') && str_contains($normalized, 'ffmpeg')) {
            return true;
        }

        return false;
    }
}
