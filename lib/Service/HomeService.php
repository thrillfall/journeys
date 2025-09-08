<?php
namespace OCA\Journeys\Service;

use OCP\IConfig;

class HomeService {
    private IConfig $config;
    private HomeLocationDetector $homeLocationDetector;

    public function __construct(IConfig $config, HomeLocationDetector $homeLocationDetector) {
        $this->config = $config;
        $this->homeLocationDetector = $homeLocationDetector;
    }

    /**
     * Try to read user's cached home from config.
     * Returns ['lat'=>float,'lon'=>float,'radiusKm'=>float,'name'?:string]|null
     */
    private function getHomeFromConfig(string $userId): ?array {
        try {
            $raw = $this->config->getUserValue($userId, 'journeys', 'home');
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['lat'], $data['lon'])) {
                if (!isset($data['radiusKm'])) {
                    $data['radiusKm'] = 50.0;
                }
                return [
                    'lat' => (float)$data['lat'],
                    'lon' => (float)$data['lon'],
                    'radiusKm' => (float)$data['radiusKm'],
                    'name' => $data['name'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return null;
    }

    /**
     * Persist user's home in config (JSON encoded).
     */
    private function setHomeInConfig(string $userId, array $home): void {
        try {
            $payload = [
                'lat' => (float)$home['lat'],
                'lon' => (float)$home['lon'],
                'radiusKm' => isset($home['radiusKm']) ? (float)$home['radiusKm'] : 50.0,
                'name' => $home['name'] ?? null,
            ];
            $this->config->setUserValue($userId, 'journeys', 'home', json_encode($payload));
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * Detect home using provided images array. Returns same structure as getHomeFromConfig or null.
     */
    private function detectHome(array $images, float $defaultRadiusKm = 50.0): ?array {
        try {
            $det = $this->homeLocationDetector->detect($images);
            if ($det) {
                return [
                    'lat' => (float)$det['lat'],
                    'lon' => (float)$det['lon'],
                    'radiusKm' => $defaultRadiusKm,
                    'name' => $det['name'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return null;
    }

    /**
     * Resolve home for a run, with source indicator.
     * @return array{home: ?array, source: string} source in {provided, config, detected, none}
     */
    public function resolveHome(string $userId, array $allImages, ?array $providedHome, float $defaultRadiusKm = 50.0, bool $storeIfDetected = true): array {
        if ($providedHome !== null) {
            $home = $providedHome;
            if (!isset($home['radiusKm'])) {
                $home['radiusKm'] = $defaultRadiusKm;
            }
            return ['home' => $home, 'source' => 'provided'];
        }
        $cached = $this->getHomeFromConfig($userId);
        if ($cached !== null) {
            return ['home' => $cached, 'source' => 'config'];
        }
        $detected = $this->detectHome($allImages, $defaultRadiusKm);
        if ($detected !== null) {
            if ($storeIfDetected) {
                $this->setHomeInConfig($userId, $detected);
            }
            return ['home' => $detected, 'source' => 'detected'];
        }
        return ['home' => null, 'source' => 'none'];
    }
}
