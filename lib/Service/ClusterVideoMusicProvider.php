<?php
namespace OCA\Journeys\Service;

class ClusterVideoMusicProvider {
    public function pickRandomTrack(): ?string {
        $dataDir = realpath(dirname(__DIR__, 2) . '/data');
        if ($dataDir === false || !is_dir($dataDir)) {
            return null;
        }

        $tracks = glob($dataDir . DIRECTORY_SEPARATOR . '*.mp3') ?: [];
        if ($tracks === false || empty($tracks)) {
            return null;
        }

        shuffle($tracks);
        foreach ($tracks as $candidate) {
            if (is_string($candidate) && $candidate !== '' && file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
