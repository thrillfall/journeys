<?php
namespace OCA\Journeys\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IConfig;

use OCA\Journeys\Service\AlbumCreator;
use OCA\Journeys\Service\RenderedVideoLister;
use OCA\Journeys\Service\VideoRenderJobScheduler;

class PersonalSettingsController extends Controller {
    private $userSession;
    private $clusteringManager;
    private $userConfig;
    private AlbumCreator $albumCreator;
    private VideoRenderJobScheduler $videoRenderJobScheduler;
    private RenderedVideoLister $renderedVideoLister;

    public function __construct($appName, IRequest $request, IUserSession $userSession,
        \OCA\Journeys\Service\ClusteringManager $clusteringManager,
        IConfig $userConfig,
        AlbumCreator $albumCreator,
        VideoRenderJobScheduler $videoRenderJobScheduler,
        RenderedVideoLister $renderedVideoLister,
    ) {
        parent::__construct($appName, $request);
        $this->userSession = $userSession;
        $this->clusteringManager = $clusteringManager;
        $this->userConfig = $userConfig;
        $this->albumCreator = $albumCreator;
        $this->videoRenderJobScheduler = $videoRenderJobScheduler;
        $this->renderedVideoLister = $renderedVideoLister;
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

        $parseTs = static function($value): ?int {
            if ($value === null) {
                return null;
            }
            $value = is_string($value) ? trim($value) : (string)$value;
            if ($value === '') {
                return null;
            }
            try {
                return (new \DateTimeImmutable($value))->getTimestamp();
            } catch (\Throwable $e) {
                return null;
            }
        };

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
        $includeSharedImages = filter_var($this->request->getParam('includeSharedImages') ?? false, FILTER_VALIDATE_BOOLEAN);
        $mergeAdjacent = filter_var($this->request->getParam('mergeAdjacent') ?? true, FILTER_VALIDATE_BOOLEAN);

        $rangeFrom = $this->request->getParam('rangeFrom');
        $rangeTo = $this->request->getParam('rangeTo');
        if (($rangeFrom === null || trim((string)$rangeFrom) === '') && ($rangeTo === null || trim((string)$rangeTo) === '')) {
            $rangeFrom = $this->userConfig->getUserValue($userId, 'journeys', 'rangeFrom', '');
            $rangeTo = $this->userConfig->getUserValue($userId, 'journeys', 'rangeTo', '');
        }
        $fromTs = $parseTs($rangeFrom);
        $toTs = $parseTs($rangeTo);
        if ($fromTs !== null && $toTs !== null && $fromTs > $toTs) {
            return new JSONResponse(['error' => 'Invalid date range: from must be <= to'], 400);
        }

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
        $this->userConfig->setUserValue($userId, 'journeys', 'includeSharedImages', $includeSharedImages ? '1' : '0');
        $this->userConfig->setUserValue($userId, 'journeys', 'mergeAdjacent', $mergeAdjacent ? '1' : '0');
        $this->userConfig->setUserValue($userId, 'journeys', 'rangeFrom', $rangeFrom !== null ? trim((string)$rangeFrom) : '');
        $this->userConfig->setUserValue($userId, 'journeys', 'rangeTo', $rangeTo !== null ? trim((string)$rangeTo) : '');
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
        // New: showLocationSubtitles toggle (per-image place captions in video)
        $showLocationSubtitles = filter_var($this->request->getParam('showLocationSubtitles') ?? true, FILTER_VALIDATE_BOOLEAN);
        $this->userConfig->setUserValue($userId, 'journeys', 'showLocationSubtitles', $showLocationSubtitles ? '1' : '0');
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
        $result = $this->clusteringManager->clusterForUser($userId, $maxTimeGap, $maxDistanceKm, $minClusterSize, $homeAware, $home, null, false, 2, false, $includeGroupFolders, $includeSharedImages, $fromTs, $toTs, null, null, $mergeAdjacent);
        if (!empty($result['fetchStats']) && $includeSharedImages && ($result['fetchStats']['shared'] ?? 0) === 0) {
            $result['warning'] = 'No shared images were included. Ensure the shared photos are visible under "Shared with you".';
        }
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
        $rangeFrom = $this->request->getParam('rangeFrom');
        $rangeTo = $this->request->getParam('rangeTo');
        try {
            $this->userConfig->setUserValue($userId, 'journeys', 'minClusterSize', $minClusterSize);
            $this->userConfig->setUserValue($userId, 'journeys', 'maxTimeGap', $maxTimeGap);
            $this->userConfig->setUserValue($userId, 'journeys', 'maxDistanceKm', $maxDistanceKm);
            $includeGroupFolders = filter_var($this->request->getParam('includeGroupFolders') ?? false, FILTER_VALIDATE_BOOLEAN);
            $includeSharedImages = filter_var($this->request->getParam('includeSharedImages') ?? false, FILTER_VALIDATE_BOOLEAN);
            $mergeAdjacent = filter_var($this->request->getParam('mergeAdjacent') ?? true, FILTER_VALIDATE_BOOLEAN);
            $this->userConfig->setUserValue($userId, 'journeys', 'includeGroupFolders', $includeGroupFolders ? '1' : '0');
            $this->userConfig->setUserValue($userId, 'journeys', 'includeSharedImages', $includeSharedImages ? '1' : '0');
            $this->userConfig->setUserValue($userId, 'journeys', 'mergeAdjacent', $mergeAdjacent ? '1' : '0');
            $this->userConfig->setUserValue($userId, 'journeys', 'rangeFrom', $rangeFrom !== null ? trim((string)$rangeFrom) : '');
            $this->userConfig->setUserValue($userId, 'journeys', 'rangeTo', $rangeTo !== null ? trim((string)$rangeTo) : '');
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
            // New: showLocationSubtitles toggle
            $showLocationSubtitles = filter_var($this->request->getParam('showLocationSubtitles') ?? true, FILTER_VALIDATE_BOOLEAN);
            $this->userConfig->setUserValue($userId, 'journeys', 'showLocationSubtitles', $showLocationSubtitles ? '1' : '0');
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
        $rangeFrom = (string)$this->userConfig->getUserValue($userId, 'journeys', 'rangeFrom', '');
        $rangeTo = (string)$this->userConfig->getUserValue($userId, 'journeys', 'rangeTo', '');
        $homeName = null;
        $includeGroupFolders = (bool)((int)$this->userConfig->getUserValue($userId, 'journeys', 'includeGroupFolders', 0));
        $includeSharedImages = (bool)((int)$this->userConfig->getUserValue($userId, 'journeys', 'includeSharedImages', 0));
        $mergeAdjacent = (bool)((int)$this->userConfig->getUserValue($userId, 'journeys', 'mergeAdjacent', 1));
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
        $showLocationSubtitles = (bool)((int)$this->userConfig->getUserValue($userId, 'journeys', 'showLocationSubtitles', 1));
        $boostFaces = (bool)((int)$this->userConfig->getUserValue($userId, 'journeys', 'boostFaces', 1));
        $videoOrientation = (string)$this->userConfig->getUserValue($userId, 'journeys', 'videoOrientation', 'portrait');
        return new JSONResponse([
            'minClusterSize' => $minClusterSize,
            'maxTimeGap' => $maxTimeGap,
            'maxDistanceKm' => $maxDistanceKm,
            'includeGroupFolders' => $includeGroupFolders,
            'includeSharedImages' => $includeSharedImages,
            'mergeAdjacent' => $mergeAdjacent,
            'rangeFrom' => trim($rangeFrom) !== '' ? $rangeFrom : null,
            'rangeTo' => trim($rangeTo) !== '' ? $rangeTo : null,
            'homeAwareEnabled' => $homeAware,
            'homeLat' => $homeLat !== '' ? $homeLat : null,
            'homeLon' => $homeLon !== '' ? $homeLon : null,
            'homeRadiusKm' => (float)$homeRadiusKm,
            'homeName' => $homeName,
            'autoGenerateVideos' => $autoGenerateVideos,
            'includeMotionFromGCam' => $includeMotionFromGCam,
            'showVideoTitle' => $showVideoTitle,
            'showLocationSubtitles' => $showLocationSubtitles,
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

        // Sorted newest-first by RenderedVideoLister, so the first match per cluster is the latest.
        $renderedVideos = $this->renderedVideoLister->listForUser($userId);

        $clusters = array_map(function (array $cluster) use ($userId, $renderedVideos) {
            $imageCount = 0;
            if (!empty($cluster['album_id'])) {
                $imageCount = count($this->albumCreator->getAlbumFileIdsForUser($userId, (int)$cluster['album_id']));
            }

            $needle = $this->buildVideoNameNeedle((string)($cluster['name'] ?? ''));
            $matchedVideo = null;
            if ($needle !== '') {
                foreach ($renderedVideos as $video) {
                    if (str_contains(strtolower((string)$video['name']), $needle)) {
                        $matchedVideo = $video;
                        break;
                    }
                }
            }

            return [
                'id' => (int)$cluster['album_id'],
                'name' => $cluster['name'],
                'customName' => $cluster['custom_name'] ?? null,
                'imageCount' => $imageCount,
                'location' => $cluster['location'],
                'dateRange' => [
                    'start' => $cluster['start_dt'],
                    'end' => $cluster['end_dt'],
                ],
                'hasVideo' => $matchedVideo !== null,
                'videoFileId' => $matchedVideo !== null ? (int)$matchedVideo['fileId'] : null,
                'videoName' => $matchedVideo !== null ? (string)$matchedVideo['name'] : null,
            ];
        }, $tracked);

        $trackedIds = array_map(static function (array $cluster): int {
            return isset($cluster['album_id']) ? (int)$cluster['album_id'] : 0;
        }, $tracked);

        $allAlbums = $this->albumCreator->getAllAlbumsForUser($userId);
        $albums = array_map(static function (array $album) use ($trackedIds) {
            $id = isset($album['album_id']) ? (int)$album['album_id'] : 0;
            return [
                'id' => $id,
                'name' => (string)($album['name'] ?? ''),
                'isCluster' => in_array($id, $trackedIds, true),
            ];
        }, $allAlbums);

        return new JSONResponse([
            'clusters' => $clusters,
            'albums' => $albums,
        ]);
    }

    /**
     * @NoAdminRequired
     */
    public function updateClusterName(): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'No user'], 401);
        }
        $userId = $user->getUID();

        $albumIdParam = $this->request->getParam('albumId');
        $albumId = (int)$albumIdParam;
        if ($albumId <= 0) {
            return new JSONResponse(['error' => 'Invalid albumId'], 400);
        }

        $trackedIds = $this->albumCreator->getTrackedAlbumIds($userId);
        if (!in_array($albumId, $trackedIds, true)) {
            return new JSONResponse(['error' => 'Album not found'], 404);
        }

        $rawCustom = $this->request->getParam('customName');
        $customName = null;
        if (is_string($rawCustom)) {
            $trimmed = trim($rawCustom);
            $customName = $trimmed !== '' ? $trimmed : null;
        }

        $ok = $this->albumCreator->setCustomName($userId, $albumId, $customName);
        if (!$ok) {
            return new JSONResponse(['error' => 'Failed to update custom name'], 500);
        }

        // Keep the underlying Photos album title in sync: use the custom name when set,
        // restore the auto-derived name on clear (so re-deriving / fallbacks remain accurate).
        $autoName = null;
        foreach ($this->albumCreator->getTrackedClusters($userId) as $row) {
            if ((int)($row['album_id'] ?? 0) === $albumId) {
                $autoName = (string)($row['name'] ?? '');
                break;
            }
        }
        if ($customName !== null) {
            $this->albumCreator->renamePhotosAlbum($userId, $albumId, $customName);
        } elseif ($autoName !== null && $autoName !== '') {
            $this->albumCreator->renamePhotosAlbum($userId, $albumId, $autoName);
        }

        return new JSONResponse([
            'id' => $albumId,
            'name' => $autoName,
            'customName' => $customName,
        ]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function renderClusterVideo(): JSONResponse {
        return $this->enqueueRender('portrait');
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function renderClusterVideoLandscape(): JSONResponse {
        return $this->enqueueRender('landscape');
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function listRenderedVideos(): JSONResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'No user'], 401);
        }
        return new JSONResponse([
            'videos' => $this->renderedVideoLister->listForUser($user->getUID()),
        ]);
    }

    private function enqueueRender(string $orientation): JSONResponse {
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

        $fileIds = $this->albumCreator->getAlbumFileIdsForUser($userId, $albumId);
        if (empty($fileIds)) {
            return new JSONResponse(['error' => 'Album not found or empty'], 404);
        }

        $this->videoRenderJobScheduler->enqueue($userId, $albumId, $orientation);

        return new JSONResponse([
            'success' => true,
            'queued' => true,
            'albumId' => $albumId,
            'orientation' => $orientation,
        ]);
    }

    private function buildVideoNameNeedle(string $clusterName): string {
        // Mirror VideoRenderPrimitives::sanitizeFileName() so the cluster name we
        // search for matches what was actually written to disk.
        $name = str_replace(['\\', '/'], '-', $clusterName);
        $name = preg_replace('/[^A-Za-z0-9\.\-_ ]+/', '', $name) ?? '';
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        return strtolower(trim($name));
    }
}
