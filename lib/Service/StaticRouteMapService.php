<?php
namespace OCA\Journeys\Service;

use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Renders a static travel-route map (PNG) for a sequence of geolocated points:
 * a real OSM raster basemap with the route line and numbered stop markers
 * composited on top. The image is generated server-side (GD) and cached in
 * app-data keyed by a hash of the points, so the public share page can embed it
 * as a plain <img> — no client JS and no CSP changes.
 *
 * Tiles come from a Web-Mercator (z/x/y) raster source, default OpenStreetMap.
 * OSM's tile usage policy requires a descriptive User-Agent and visible
 * attribution — both are honored (attribution is burned into the image). Admins
 * can repoint the source via the `mapTileUrl` app config value.
 */
class StaticRouteMapService {

    private const TILE = 256;
    private const WIDTH = 900;
    private const HEIGHT = 540;
    private const PAD = 70;       // keep markers off the canvas edge
    private const MAX_ZOOM = 16;
    private const DEFAULT_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

    public function __construct(
        private IAppDataFactory $appDataFactory,
        private IClientService $clientService,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * PNG bytes for the given route, or null if it can't be produced (GD
     * missing, no tiles reachable, <2 points). Cached after first render.
     *
     * @param array<int,array{lat:float,lon:float}> $points in travel order
     */
    public function pngForPoints(array $points): ?string {
        if (count($points) < 2 || !\function_exists('imagecreatetruecolor')) {
            return null;
        }
        $key = sha1(json_encode(array_map(static fn($p) => [
            round($p['lat'], 5), round($p['lon'], 5),
        ], $points))) . '.png';

        $folder = $this->mapsFolder();
        if ($folder !== null) {
            try {
                $cached = $folder->getFile($key)->getContent();
                if (is_string($cached) && $cached !== '') {
                    return $cached;
                }
            } catch (\Throwable $e) {
                // not cached, or the cached file is missing/unreadable — (re)render below
            }
        }

        $png = $this->render($points);
        if ($png === null) {
            return null;
        }
        if ($folder !== null) {
            $this->store($folder, $key, $png);
        }
        return $png;
    }

    /** Write (creating or overwriting) the cached PNG; failures are non-fatal. */
    private function store(ISimpleFolder $folder, string $name, string $content): void {
        try {
            $file = $folder->fileExists($name) ? $folder->getFile($name) : $folder->newFile($name);
            $file->putContent($content);
        } catch (\Throwable $e) {
            $this->logger->warning('journeys: could not cache route map: ' . $e->getMessage());
        }
    }

    private function mapsFolder(): ?ISimpleFolder {
        try {
            $appData = $this->appDataFactory->get('journeys');
            try {
                return $appData->getFolder('route-maps');
            } catch (NotFoundException $e) {
                return $appData->newFolder('route-maps');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('journeys: route-map app-data unavailable: ' . $e->getMessage());
            return null;
        }
    }

    /** @param array<int,array{lat:float,lon:float}> $points */
    private function render(array $points): ?string {
        $lats = array_column($points, 'lat');
        $lons = array_column($points, 'lon');
        $minLat = min($lats); $maxLat = max($lats);
        $minLon = min($lons); $maxLon = max($lons);

        // Largest zoom at which the bounding box still fits inside the padded
        // canvas (spans grow with zoom, so the first fit scanning down is the max).
        $z = self::MAX_ZOOM;
        for (; $z > 1; $z--) {
            $spanX = abs($this->lonToPx($maxLon, $z) - $this->lonToPx($minLon, $z));
            $spanY = abs($this->latToPx($minLat, $z) - $this->latToPx($maxLat, $z));
            if ($spanX <= self::WIDTH - 2 * self::PAD && $spanY <= self::HEIGHT - 2 * self::PAD) {
                break;
            }
        }

        $centerX = $this->lonToPx(($minLon + $maxLon) / 2, $z);
        $centerY = $this->latToPx(($minLat + $maxLat) / 2, $z);
        $originX = $centerX - self::WIDTH / 2;
        $originY = $centerY - self::HEIGHT / 2;

        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 233, 231, 227));

        // Stitch the tiles that overlap the viewport.
        $worldTiles = 1 << $z;
        $tx0 = (int)floor($originX / self::TILE); $tx1 = (int)floor(($originX + self::WIDTH) / self::TILE);
        $ty0 = (int)floor($originY / self::TILE); $ty1 = (int)floor(($originY + self::HEIGHT) / self::TILE);
        $fetched = false;
        for ($tx = $tx0; $tx <= $tx1; $tx++) {
            for ($ty = $ty0; $ty <= $ty1; $ty++) {
                if ($ty < 0 || $ty >= $worldTiles) {
                    continue;
                }
                $wrapX = (($tx % $worldTiles) + $worldTiles) % $worldTiles;
                $tile = $this->fetchTile($z, $wrapX, $ty);
                if ($tile === null) {
                    continue;
                }
                $fetched = true;
                imagecopy($canvas, $tile, (int)round($tx * self::TILE - $originX),
                    (int)round($ty * self::TILE - $originY), 0, 0, self::TILE, self::TILE);
                imagedestroy($tile);
            }
        }
        if (!$fetched) {
            imagedestroy($canvas);
            return null; // no basemap → don't ship a blank box
        }

        $pts = array_map(fn($p) => [
            'x' => (int)round($this->lonToPx($p['lon'], $z) - $originX),
            'y' => (int)round($this->latToPx($p['lat'], $z) - $originY),
        ], $points);

        $accent = imagecolorallocate($canvas, 0x00, 0x67, 0x9e);
        $white = imagecolorallocate($canvas, 255, 255, 255);

        imagesetthickness($canvas, 4);
        for ($i = 1, $n = count($pts); $i < $n; $i++) {
            imageline($canvas, $pts[$i - 1]['x'], $pts[$i - 1]['y'], $pts[$i]['x'], $pts[$i]['y'], $accent);
        }
        imagesetthickness($canvas, 1);

        foreach ($pts as $i => $pt) {
            imagefilledellipse($canvas, $pt['x'], $pt['y'], 28, 28, $white);
            imagefilledellipse($canvas, $pt['x'], $pt['y'], 23, 23, $accent);
            $label = (string)($i + 1);
            $fw = imagefontwidth(5) * strlen($label);
            $fh = imagefontheight(5);
            imagestring($canvas, 5, $pt['x'] - intdiv($fw, 2), $pt['y'] - intdiv($fh, 2), $label, $white);
        }

        $this->drawAttribution($canvas);

        ob_start();
        imagepng($canvas);
        $png = ob_get_clean();
        imagedestroy($canvas);
        return $png !== '' ? $png : null;
    }

    /** @param \GdImage $canvas */
    private function drawAttribution($canvas): void {
        $text = '(c) OpenStreetMap contributors';
        $fw = imagefontwidth(2) * strlen($text);
        $fh = imagefontheight(2);
        $x = self::WIDTH - $fw - 6;
        $y = self::HEIGHT - $fh - 4;
        imagefilledrectangle($canvas, $x - 4, $y - 2, self::WIDTH, self::HEIGHT,
            imagecolorallocate($canvas, 255, 255, 255));
        imagestring($canvas, 2, $x, $y, $text, imagecolorallocate($canvas, 40, 40, 40));
    }

    private function fetchTile(int $z, int $x, int $y): ?\GdImage {
        $tmpl = $this->config->getAppValue('journeys', 'mapTileUrl', self::DEFAULT_TILE_URL);
        $url = strtr($tmpl, ['{z}' => (string)$z, '{x}' => (string)$x, '{y}' => (string)$y]);
        $version = $this->config->getAppValue('journeys', 'installed_version', '0');
        try {
            $body = $this->clientService->newClient()->get($url, [
                'timeout' => 10,
                'headers' => [
                    'User-Agent' => 'Nextcloud-Journeys/' . $version
                        . ' (Nextcloud app; +https://apps.nextcloud.com/apps/journeys)',
                ],
            ])->getBody();
            $img = @imagecreatefromstring(is_string($body) ? $body : (string)stream_get_contents($body));
            return $img instanceof \GdImage ? $img : null;
        } catch (\Throwable $e) {
            $this->logger->warning('journeys: tile fetch failed (' . $url . '): ' . $e->getMessage());
            return null;
        }
    }

    /** Web-Mercator longitude → absolute pixel X at zoom $z. */
    private function lonToPx(float $lon, int $z): float {
        return (($lon + 180) / 360) * (1 << $z) * self::TILE;
    }

    /** Web-Mercator latitude → absolute pixel Y at zoom $z. */
    private function latToPx(float $lat, int $z): float {
        $rad = deg2rad($lat);
        return (1 - log(tan($rad) + 1 / cos($rad)) / M_PI) / 2 * (1 << $z) * self::TILE;
    }
}
