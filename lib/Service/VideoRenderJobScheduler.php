<?php
namespace OCA\Journeys\Service;

use OCP\BackgroundJob\IJobList;
use OCA\Journeys\Model\Image;

class VideoRenderJobScheduler {
    private IJobList $jobList;

    public function __construct(IJobList $jobList) {
        $this->jobList = $jobList;
    }

    /**
     * Enqueue background job to render a cluster video.
     * @param string $userId
     * @param int $albumId
     * @param 'portrait'|'landscape' $orientation
     */
    public function enqueue(string $userId, int $albumId, string $orientation = 'portrait'): void {
        $this->jobList->add(\OCA\Journeys\BackgroundJob\RenderClusterVideoJob::class, [
            'userId' => $userId,
            'albumId' => (int)$albumId,
            'orientation' => $orientation === 'landscape' ? 'landscape' : 'portrait',
        ]);
    }

    /**
     * Conditionally enqueue rendering only if the cluster is away from home.
     * @param Image[] $cluster
     * @param array{lat:float,lon:float,radiusKm:float} $home
     */
    public function enqueueIfAway(string $userId, int $albumId, array $cluster, array $home, string $orientation = 'portrait'): void {
        if ($this->isAwayCluster($cluster, $home)) {
            $this->enqueue($userId, $albumId, $orientation);
        }
    }

    /**
     * @param Image[] $cluster
     */
    private function isAwayCluster(array $cluster, array $home): bool {
        if (!isset($home['lat'], $home['lon'], $home['radiusKm'])) {
            return false;
        }
        $lat0 = (float)$home['lat'];
        $lon0 = (float)$home['lon'];
        $radius = (float)$home['radiusKm'];
        foreach ($cluster as $img) {
            if ($img instanceof Image && $img->lat !== null && $img->lon !== null) {
                $d = $this->haversine((float)$img->lat, (float)$img->lon, $lat0, $lon0);
                if ($d > $radius) {
                    return true;
                }
            }
        }
        return false;
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $R = 6371.0; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $R * $c;
    }
}
