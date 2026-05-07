<?php
namespace OCA\Journeys\Command;

use OCA\Journeys\Service\ImageFetcher;
use OCA\Journeys\Service\Clusterer;
use OCA\Journeys\Service\AlbumCreator; // ensure import for marker constant

use OCA\Journeys\Service\ClusterLocationResolver;
use OCA\Journeys\Service\SimplePlaceResolver;
use OCA\Journeys\Service\ImageLocationInterpolator; // ADDED
use OCP\IDBConnection;
use OCA\Journeys\Service\HomeService;


use OCA\Journeys\Model\Image;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCP\IUserManager;
use OCP\Files\IRootFolder;
use OCA\Photos\Album\AlbumMapper;
use OCA\Journeys\Service\ClusteringManager;

class ClusterAndCreateAlbumsCommand extends Command {
    protected static $defaultName = 'journeys:cluster';

    private ClusteringManager $clusteringManager;
    private AlbumMapper $albumMapper;
    private ImageFetcher $imageFetcher;
    private HomeService $homeService;

    public function __construct(
        ClusteringManager $clusteringManager,
        AlbumMapper $albumMapper,
        ImageFetcher $imageFetcher,
        HomeService $homeService
    ) {
        parent::__construct(static::$defaultName);
        $this->clusteringManager = $clusteringManager;
        $this->albumMapper = $albumMapper;
        $this->imageFetcher = $imageFetcher;
        $this->homeService = $homeService;
    }


    protected function configure(): void {
        $this
            ->setAliases(['journeys:cluster-create-albums'])
            ->setDescription('Clusters images by location and creates albums in the Photos app (incremental by default, home-aware enabled by default).')
            ->addArgument('user', InputArgument::REQUIRED, 'The ID of the user for whom to cluster images and create albums.')
            ->addArgument('maxTimeGap', InputArgument::OPTIONAL, 'Max allowed time gap in hours (if omitted, use UI setting)')
            ->addArgument('maxDistanceKm', InputArgument::OPTIONAL, 'Max allowed distance in kilometers (if omitted, use UI setting)')
            ->addOption('no-home-aware', null, InputOption::VALUE_NONE, 'Disable home-aware clustering')
            ->addOption('from-scratch', null, InputOption::VALUE_NONE, 'Recluster all images from scratch (purges previously created cluster albums)')
            ->addOption('min-cluster-size', null, InputOption::VALUE_REQUIRED, 'Minimum images per cluster (if omitted, use UI setting)')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Only cluster images taken on/after this date/time (ISO-8601 or YYYY-MM-DD)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Only cluster images taken on/before this date/time (ISO-8601 or YYYY-MM-DD)')
            ->addOption('last-years', null, InputOption::VALUE_REQUIRED, 'Only cluster images from the last N years (alternative to --from/--to)')
            ->addOption('debug-splits', null, InputOption::VALUE_NONE, 'Print why clustering split (time/distance exceeded amounts, home-aware boundaries)')
            ->addOption('home-lat', null, InputOption::VALUE_REQUIRED, 'Home latitude')
            ->addOption('home-lon', null, InputOption::VALUE_REQUIRED, 'Home longitude')
            ->addOption('home-radius', null, InputOption::VALUE_REQUIRED, 'Home radius in km (default: 50)', 50)
            ->addOption('near-time-gap', null, InputOption::VALUE_REQUIRED, 'Near-home max time gap in hours (if omitted, use UI setting)')
            ->addOption('near-distance-km', null, InputOption::VALUE_REQUIRED, 'Near-home max distance between consecutive photos in km (if omitted, use UI setting)')
            ->addOption('away-time-gap', null, InputOption::VALUE_REQUIRED, 'Away-from-home max time gap in hours (if omitted, use UI setting)')
            ->addOption('away-distance-km', null, InputOption::VALUE_REQUIRED, 'Away-from-home max distance between consecutive photos in km (if omitted, use UI setting)')
            ->addOption('recent-cutoff-days', null, InputOption::VALUE_REQUIRED, 'Skip clusters whose last image is within the past N days (default: 2, 0 disables)', 2)
            ->addOption('include-group-folders', null, InputOption::VALUE_NONE, 'Include images from Group Folders and other mounts (if omitted, use UI setting)')
            ->addOption('include-shared-images', null, InputOption::VALUE_NONE, 'Include images that are shared with the user (if omitted, use UI setting)')
            ->addOption('no-merge', null, InputOption::VALUE_NONE, 'Disable post-clustering merge pass (skip stitching adjacent same-country clusters)');
    }


    protected function execute(InputInterface $input, OutputInterface $output): int {
        $user = $input->getArgument('user');
        $cfg = null;
        try { $cfg = \OC::$server->get(\OCP\IConfig::class); } catch (\Throwable $e) { $cfg = null; }
        $rawTimeGapHours = $input->getArgument('maxTimeGap');
        $rawDistanceKm = $input->getArgument('maxDistanceKm');
        $rawMinClusterSize = $input->getOption('min-cluster-size');
        $maxTimeGap = null;
        $maxDistanceKm = null;
        $minClusterSize = null;
        if ($rawTimeGapHours !== null && $rawTimeGapHours !== '') {
            $maxTimeGap = (int)$rawTimeGapHours * 3600;
        } elseif ($cfg) {
            $maxTimeGap = (int)$cfg->getUserValue($user, 'journeys', 'maxTimeGap', 86400);
        } else {
            $maxTimeGap = 86400;
        }
        if ($maxTimeGap <= 0) {
            $maxTimeGap = 86400;
        }
        if ($rawDistanceKm !== null && $rawDistanceKm !== '') {
            $maxDistanceKm = (float)$rawDistanceKm;
        } elseif ($cfg) {
            $maxDistanceKm = (float)$cfg->getUserValue($user, 'journeys', 'maxDistanceKm', 100.0);
        } else {
            $maxDistanceKm = 100.0;
        }
        if ($maxDistanceKm <= 0) {
            $maxDistanceKm = 100.0;
        }
        if ($rawMinClusterSize !== null && $rawMinClusterSize !== '') {
            $minClusterSize = max(1, (int)$rawMinClusterSize);
        } elseif ($cfg) {
            $minClusterSize = max(1, (int)$cfg->getUserValue($user, 'journeys', 'minClusterSize', 3));
        } else {
            $minClusterSize = 3;
        }
        // Home-aware is enabled by default; allow explicit opt-out with --no-home-aware
        $homeAware = true;
        if ($input->getOption('no-home-aware')) {
            $homeAware = false;
        }
        $homeLat = $input->getOption('home-lat');
        $homeLon = $input->getOption('home-lon');
        $homeRadius = (float)$input->getOption('home-radius');
        $optNearTimeGap = $input->getOption('near-time-gap');
        $optNearDistanceKm = $input->getOption('near-distance-km');
        $optAwayTimeGap = $input->getOption('away-time-gap');
        $optAwayDistanceKm = $input->getOption('away-distance-km');
        if ($optNearTimeGap !== null && $optNearTimeGap !== '') {
            $nearTimeGap = (int)$optNearTimeGap * 3600;
        } elseif ($cfg) {
            $nearTimeGap = (int)$cfg->getUserValue($user, 'journeys', 'nearTimeGap', 21600);
        } else {
            $nearTimeGap = 21600;
        }
        if ($nearTimeGap <= 0) {
            $nearTimeGap = 21600;
        }
        if ($optNearDistanceKm !== null && $optNearDistanceKm !== '') {
            $nearDistanceKm = (float)$optNearDistanceKm;
        } elseif ($cfg) {
            $nearDistanceKm = (float)$cfg->getUserValue($user, 'journeys', 'nearDistanceKm', 3.0);
        } else {
            $nearDistanceKm = 3.0;
        }
        if ($optAwayTimeGap !== null && $optAwayTimeGap !== '') {
            $awayTimeGap = (int)$optAwayTimeGap * 3600;
        } elseif ($cfg) {
            $awayTimeGap = (int)$cfg->getUserValue($user, 'journeys', 'awayTimeGap', 129600);
        } else {
            $awayTimeGap = 129600;
        }
        if ($awayTimeGap <= 0) {
            $awayTimeGap = 129600;
        }
        if ($optAwayDistanceKm !== null && $optAwayDistanceKm !== '') {
            $awayDistanceKm = (float)$optAwayDistanceKm;
        } elseif ($cfg) {
            $awayDistanceKm = (float)$cfg->getUserValue($user, 'journeys', 'awayDistanceKm', 50.0);
        } else {
            $awayDistanceKm = 50.0;
        }
        $fromScratch = (bool)$input->getOption('from-scratch');
        $recentCutoffDays = max(0, (int)$input->getOption('recent-cutoff-days'));
        $includeGroupFolders = (bool)$input->getOption('include-group-folders');
        if (!$includeGroupFolders && $cfg) {
            $includeGroupFolders = (bool)((int)$cfg->getUserValue($user, 'journeys', 'includeGroupFolders', 0));
        }
        $includeSharedImages = (bool)$input->getOption('include-shared-images');
        if (!$includeSharedImages && $cfg) {
            $includeSharedImages = (bool)((int)$cfg->getUserValue($user, 'journeys', 'includeSharedImages', 0));
        }
        // Merge pass: on by default. --no-merge disables it; UI setting overrides only when the CLI flag isn't given.
        $mergeAdjacent = !(bool)$input->getOption('no-merge');
        if ($mergeAdjacent && $cfg) {
            $mergeAdjacent = (bool)((int)$cfg->getUserValue($user, 'journeys', 'mergeAdjacent', 1));
        }

        $debugSplits = (bool)$input->getOption('debug-splits');

        $fromTs = null;
        $toTs = null;
        $rawFrom = $input->getOption('from');
        $rawTo = $input->getOption('to');
        $rawLastYears = $input->getOption('last-years');
        if (($rawFrom !== null && $rawFrom !== '') || ($rawTo !== null && $rawTo !== '')) {
            try {
                if ($rawFrom !== null && $rawFrom !== '') {
                    $fromTs = (new \DateTimeImmutable((string)$rawFrom))->getTimestamp();
                }
                if ($rawTo !== null && $rawTo !== '') {
                    $toTs = (new \DateTimeImmutable((string)$rawTo))->getTimestamp();
                }
            } catch (\Throwable $e) {
                $output->writeln('<error>Invalid --from/--to value. Use ISO-8601 or YYYY-MM-DD.</error>');
                return Command::FAILURE;
            }
        } elseif ($rawLastYears !== null && $rawLastYears !== '') {
            $years = (int)$rawLastYears;
            if ($years <= 0) {
                $output->writeln('<error>--last-years must be a positive integer.</error>');
                return Command::FAILURE;
            }
            $fromTs = (new \DateTimeImmutable('now'))->modify('-' . $years . ' years')->getTimestamp();
            $toTs = null;
        } elseif ($cfg) {
            // Default: if the user configured a range in the UI, honor it on CLI runs too.
            try {
                $storedFrom = $cfg->getUserValue($user, 'journeys', 'rangeFrom', '');
                $storedTo = $cfg->getUserValue($user, 'journeys', 'rangeTo', '');
                if (is_string($storedFrom) && trim($storedFrom) !== '') {
                    $fromTs = (new \DateTimeImmutable($storedFrom))->getTimestamp();
                }
                if (is_string($storedTo) && trim($storedTo) !== '') {
                    $toTs = (new \DateTimeImmutable($storedTo))->getTimestamp();
                }
            } catch (\Throwable $e) {
                $fromTs = null;
                $toTs = null;
            }
        }
        if ($fromTs !== null && $toTs !== null && $fromTs > $toTs) {
            $output->writeln('<error>Invalid range: --from must be <= --to.</error>');
            return Command::FAILURE;
        }

        $home = null;
        $thresholds = null;
        if ($homeAware) {
            // Build thresholds
            $thresholds = [
                'near' => ['timeGap' => $nearTimeGap > 0 ? $nearTimeGap : 21600, 'distanceKm' => $nearDistanceKm > 0 ? $nearDistanceKm : 3.0],
                'away' => ['timeGap' => $awayTimeGap > 0 ? $awayTimeGap : 129600, 'distanceKm' => $awayDistanceKm > 0 ? $awayDistanceKm : 50.0],
            ];
            // Use provided home or detect
            if ($homeLat !== null && $homeLon !== null) {
                $home = [ 'lat' => (float)$homeLat, 'lon' => (float)$homeLon, 'radiusKm' => (float)$homeRadius ];
                $output->writeln(sprintf('<info>Using provided home:</info> lat=%.5f, lon=%.5f, radius=%.1f km', $home['lat'], $home['lon'], $home['radiusKm']));
            } else {
                // Resolve via HomeService and inform user about the source
                $images = $this->imageFetcher->fetchImagesForUser($user, $includeGroupFolders, $includeSharedImages);
                $resolved = $this->homeService->resolveHome($user, $images, null, (float)$homeRadius, true);
                $home = $resolved['home'];
                if ($home !== null) {
                    if ($resolved['source'] === 'config') {
                        $output->writeln(sprintf(
                            '<info>Using home from config:</info> lat=%.5f, lon=%.5f%s',
                            $home['lat'], $home['lon'],
                            isset($home['name']) && $home['name'] ? ", name={$home['name']}" : ''
                        ));
                    } elseif ($resolved['source'] === 'detected') {
                        $output->writeln(sprintf(
                            '<info>Detected Home Location:</info> lat=%.5f, lon=%.5f%s',
                            $home['lat'], $home['lon'],
                            isset($home['name']) && $home['name'] ? ", name={$home['name']}" : ''
                        ));
                    }
                } else {
                    $output->writeln('<comment>Could not determine home location (not enough geotagged images). Proceeding without home-aware thresholds.</comment>');
                    $homeAware = false;
                    $thresholds = null;
                }
            }
            $output->writeln(sprintf('<info>Effective settings:</info> homeAware=%s, includeGroupFolders=%s, includeSharedImages=%s, mergeAdjacent=%s, minClusterSize=%d, recentCutoffDays=%d', $homeAware ? 'true' : 'false', $includeGroupFolders ? 'true' : 'false', $includeSharedImages ? 'true' : 'false', $mergeAdjacent ? 'true' : 'false', $minClusterSize, $recentCutoffDays));
            $output->writeln(sprintf('  near: timeGap=%ds (%.1fh), distance=%.2fkm', (int)$thresholds['near']['timeGap'], (int)$thresholds['near']['timeGap']/3600.0, (float)$thresholds['near']['distanceKm']));
            $output->writeln(sprintf('  away: timeGap=%ds (%.1fh), distance=%.2fkm', (int)$thresholds['away']['timeGap'], (int)$thresholds['away']['timeGap']/3600.0, (float)$thresholds['away']['distanceKm']));
        } else {
            $output->writeln(sprintf('<info>Effective settings:</info> homeAware=%s, includeGroupFolders=%s, includeSharedImages=%s, mergeAdjacent=%s, minClusterSize=%d, recentCutoffDays=%d', 'false', $includeGroupFolders ? 'true' : 'false', $includeSharedImages ? 'true' : 'false', $mergeAdjacent ? 'true' : 'false', $minClusterSize, $recentCutoffDays));
            $output->writeln(sprintf('  global: timeGap=%ds (%.1fh), distance=%.2fkm', (int)$maxTimeGap, (int)$maxTimeGap/3600.0, (float)$maxDistanceKm));
        }

        if ($fromTs !== null || $toTs !== null) {
            $fromLabel = $fromTs !== null ? (new \DateTimeImmutable('@' . $fromTs))->format('Y-m-d') : '∅';
            $toLabel = $toTs !== null ? (new \DateTimeImmutable('@' . $toTs))->format('Y-m-d') : '∅';
            $output->writeln(sprintf('<info>Date range:</info> from=%s to=%s', $fromLabel, $toLabel));
        }
        $clustersPrinted = false;
        $progressCallback = function(array $cluster) use ($output, &$clustersPrinted) {
            $clustersPrinted = true;
            $message = sprintf(
                "Cluster %d: Created album '%s' with %d images.",
                ($cluster['index'] ?? 0) + 1,
                $cluster['albumName'] ?? 'Unknown',
                $cluster['imageCount'] ?? 0,
            );
            if (!empty($cluster['location'])) {
                $message .= sprintf(' (Location: %s)', $cluster['location']);
            }
            $output->writeln($message);
        };

        $splitCallback = null;
        if ($debugSplits) {
            $output->writeln('<comment>Split debug enabled: printing why clustering starts a new cluster.</comment>');
            $splitCallback = function(array $ev) use ($output) {
                try {
                    $type = isset($ev['type']) ? (string)$ev['type'] : '';
                    if ($type === 'split') {
                        $before = null;
                        $after = null;
                        if (isset($ev['cluster_index_before_global'], $ev['cluster_index_after_global'])) {
                            $before = (int)$ev['cluster_index_before_global'];
                            $after = (int)$ev['cluster_index_after_global'];
                        } elseif (isset($ev['cluster_index_before'], $ev['cluster_index_after'])) {
                            $before = (int)$ev['cluster_index_before'];
                            $after = (int)$ev['cluster_index_after'];
                        }
                        $boundaryStr = '';
                        if ($before !== null && $after !== null) {
                            $boundaryStr = sprintf(' raw=%d->%d', $before + 1, $after + 1);
                            if (isset($ev['cluster_index_before'], $ev['cluster_index_after']) && isset($ev['cluster_index_before_global'], $ev['cluster_index_after_global'])) {
                                $boundaryStr .= sprintf(' (local=%d->%d)', (int)$ev['cluster_index_before'] + 1, (int)$ev['cluster_index_after'] + 1);
                            }
                        }
                        $reason = isset($ev['reason']) ? (string)$ev['reason'] : 'unknown';
                        $timeGap = isset($ev['time_gap_seconds']) ? (int)$ev['time_gap_seconds'] : 0;
                        $maxTime = isset($ev['max_time_gap_seconds']) ? (int)$ev['max_time_gap_seconds'] : 0;
                        $timeExBy = isset($ev['time_exceeded_by_seconds']) ? (int)$ev['time_exceeded_by_seconds'] : 0;
                        $prev = isset($ev['prev']) && is_array($ev['prev']) ? $ev['prev'] : null;
                        $curr = isset($ev['curr']) && is_array($ev['curr']) ? $ev['curr'] : null;

                        $prevFid = is_array($prev) && isset($prev['fileid']) ? (int)$prev['fileid'] : 0;
                        $currFid = is_array($curr) && isset($curr['fileid']) ? (int)$curr['fileid'] : 0;
                        $prevDt = is_array($prev) && isset($prev['datetaken']) ? (string)$prev['datetaken'] : '';
                        $currDt = is_array($curr) && isset($curr['datetaken']) ? (string)$curr['datetaken'] : '';
                        $prevPath = is_array($prev) && isset($prev['path']) ? (string)$prev['path'] : '';
                        $currPath = is_array($curr) && isset($curr['path']) ? (string)$curr['path'] : '';
                        $prevSrc = is_array($prev) && isset($prev['source']) ? (string)$prev['source'] : '';
                        $currSrc = is_array($curr) && isset($curr['source']) ? (string)$curr['source'] : '';
                        $srcStr = '';
                        if ($prevSrc !== '' || $currSrc !== '') {
                            $srcStr = sprintf(' src=%s->%s', $prevSrc !== '' ? $prevSrc : '?', $currSrc !== '' ? $currSrc : '?');
                        }

                        $prevHasLoc = is_array($prev) && isset($prev['lat'], $prev['lon']) && $prev['lat'] !== null && $prev['lon'] !== null;
                        $currHasLoc = is_array($curr) && isset($curr['lat'], $curr['lon']) && $curr['lat'] !== null && $curr['lon'] !== null;
                        $locStr = sprintf(' loc=prev:%d curr:%d', $prevHasLoc ? 1 : 0, $currHasLoc ? 1 : 0);

                        if ($reason === 'distance_exceeded') {
                            $dist = isset($ev['distance_km']) ? (float)$ev['distance_km'] : 0.0;
                            $maxDist = isset($ev['max_distance_km']) ? (float)$ev['max_distance_km'] : 0.0;
                            $distExBy = array_key_exists('distance_exceeded_by_km', $ev) ? $ev['distance_exceeded_by_km'] : null;
                            $distExByStr = $distExBy === null ? 'n/a' : sprintf('%.3f', (float)$distExBy);
                            $prevGeo = isset($ev['prev_geo']) && is_array($ev['prev_geo']) ? $ev['prev_geo'] : null;
                            $prevGeoStr = '';
                            if (is_array($prevGeo) && isset($prevGeo['fileid'])) {
                                $prevGeoStr = sprintf(
                                    ' prevGeo(fileid=%d dt=%s path=%s)',
                                    isset($prevGeo['fileid']) ? (int)$prevGeo['fileid'] : 0,
                                    isset($prevGeo['datetaken']) ? (string)$prevGeo['datetaken'] : '',
                                    isset($prevGeo['path']) ? (string)$prevGeo['path'] : '',
                                );
                            }
                            $output->writeln(sprintf(
                                "<comment>SPLIT (distance):</comment>%s%s%s dist=%.3fkm max=%.3fkm exceeded_by=%skm prev(fileid=%d dt=%s path=%s) curr(fileid=%d dt=%s path=%s)%s",
                                $boundaryStr,
                                $srcStr,
                                $locStr,
                                $dist,
                                $maxDist,
                                $distExByStr,
                                $prevFid,
                                $prevDt,
                                $prevPath,
                                $currFid,
                                $currDt,
                                $currPath,
                                $prevGeoStr,
                            ));
                        } else {
                            $output->writeln(sprintf(
                                "<comment>SPLIT (time):</comment>%s%s%s gap=%ds max=%ds exceeded_by=%ds prev(fileid=%d dt=%s path=%s) curr(fileid=%d dt=%s path=%s)",
                                $boundaryStr,
                                $srcStr,
                                $locStr,
                                $timeGap,
                                $maxTime,
                                $timeExBy,
                                $prevFid,
                                $prevDt,
                                $prevPath,
                                $currFid,
                                $currDt,
                                $currPath,
                            ));
                        }
                        return;
                    }

                    if ($type === 'merge') {
                        $reason = isset($ev['reason']) ? (string)$ev['reason'] : '';
                        $gapDays = isset($ev['gap_days']) ? (float)$ev['gap_days'] : 0.0;
                        $distKm = isset($ev['distance_km']) ? (float)$ev['distance_km'] : 0.0;
                        $country = isset($ev['country']) ? (string)$ev['country'] : '';
                        $aEnd = isset($ev['a_end']) && is_array($ev['a_end']) ? $ev['a_end'] : [];
                        $bStart = isset($ev['b_start']) && is_array($ev['b_start']) ? $ev['b_start'] : [];
                        $label = $reason === 'same_country_through_noise' ? 'MERGE (through noise)' : 'MERGE (same country)';
                        $line = sprintf(
                            "<info>%s:</info> country=%s gap=%.2fd dist=%.1fkm sizes=%d+%d aEnd(dt=%s) bStart(dt=%s)",
                            $label,
                            $country,
                            $gapDays,
                            $distKm,
                            (int)($ev['cluster_a_size'] ?? 0),
                            (int)($ev['cluster_b_size'] ?? 0),
                            (string)($aEnd['datetaken'] ?? ''),
                            (string)($bStart['datetaken'] ?? ''),
                        );
                        if ($reason === 'same_country_through_noise' && isset($ev['noise_size'])) {
                            $noise = isset($ev['noise_start']) && is_array($ev['noise_start']) ? $ev['noise_start'] : [];
                            $line .= sprintf(
                                ' noise(size=%d fid=%s dt=%s lat=%s lon=%s)',
                                (int)$ev['noise_size'],
                                (string)($noise['fileid'] ?? ''),
                                (string)($noise['datetaken'] ?? ''),
                                (string)($noise['lat'] ?? ''),
                                (string)($noise['lon'] ?? ''),
                            );
                        }
                        $output->writeln($line);
                        return;
                    }

                    if ($type === 'no_merge') {
                        $reason = isset($ev['reason']) ? (string)$ev['reason'] : '';
                        $gapDays = isset($ev['gap_days']) ? (float)$ev['gap_days'] : 0.0;
                        $distKm = isset($ev['distance_km']) ? (float)$ev['distance_km'] : 0.0;
                        $countryA = array_key_exists('country_a', $ev) ? $ev['country_a'] : null;
                        $countryB = array_key_exists('country_b', $ev) ? $ev['country_b'] : null;
                        $aEnd = isset($ev['a_end']) && is_array($ev['a_end']) ? $ev['a_end'] : [];
                        $bStart = isset($ev['b_start']) && is_array($ev['b_start']) ? $ev['b_start'] : [];
                        $output->writeln(sprintf(
                            "<comment>NO MERGE (%s):</comment> countryA=%s countryB=%s gap=%.2fd dist=%.1fkm sizes=%d+%d aEnd(fid=%s dt=%s lat=%s lon=%s) bStart(fid=%s dt=%s lat=%s lon=%s)",
                            $reason,
                            $countryA === null ? 'null' : (string)$countryA,
                            $countryB === null ? 'null' : (string)$countryB,
                            $gapDays,
                            $distKm,
                            (int)($ev['cluster_a_size'] ?? 0),
                            (int)($ev['cluster_b_size'] ?? 0),
                            (string)($aEnd['fileid'] ?? ''),
                            (string)($aEnd['datetaken'] ?? ''),
                            (string)($aEnd['lat'] ?? ''),
                            (string)($aEnd['lon'] ?? ''),
                            (string)($bStart['fileid'] ?? ''),
                            (string)($bStart['datetaken'] ?? ''),
                            (string)($bStart['lat'] ?? ''),
                            (string)($bStart['lon'] ?? ''),
                        ));
                        return;
                    }

                    if ($type === 'home_boundary') {
                        $prevNear = !empty($ev['prev_near']);
                        $currNear = !empty($ev['curr_near']);
                        $home = isset($ev['home']) && is_array($ev['home']) ? $ev['home'] : [];
                        $homeR = isset($home['radiusKm']) ? (float)$home['radiusKm'] : 0.0;
                        $prev = isset($ev['prev']) && is_array($ev['prev']) ? $ev['prev'] : [];
                        $curr = isset($ev['curr']) && is_array($ev['curr']) ? $ev['curr'] : [];
                        $prevDist = array_key_exists('home_distance_km', $prev) ? $prev['home_distance_km'] : null;
                        $currDist = array_key_exists('home_distance_km', $curr) ? $curr['home_distance_km'] : null;
                        $prevDistStr = $prevDist === null ? 'n/a' : sprintf('%.3f', (float)$prevDist);
                        $currDistStr = $currDist === null ? 'n/a' : sprintf('%.3f', (float)$currDist);
                        $output->writeln(sprintf(
                            "<comment>SPLIT (home boundary):</comment> prev=%s(currDist=%skm) -> curr=%s(currDist=%skm) radius=%.3fkm prev(fileid=%d dt=%s path=%s) curr(fileid=%d dt=%s path=%s)",
                            $prevNear ? 'near' : 'away',
                            $prevDistStr,
                            $currNear ? 'near' : 'away',
                            $currDistStr,
                            $homeR,
                            isset($prev['fileid']) ? (int)$prev['fileid'] : 0,
                            isset($prev['datetaken']) ? (string)$prev['datetaken'] : '',
                            isset($prev['path']) ? (string)$prev['path'] : '',
                            isset($curr['fileid']) ? (int)$curr['fileid'] : 0,
                            isset($curr['datetaken']) ? (string)$curr['datetaken'] : '',
                            isset($curr['path']) ? (string)$curr['path'] : '',
                        ));
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            };
        }

        // Delegate clustering and album creation to ClusteringManager (home-aware optional)
        $result = $this->clusteringManager->clusterForUser(
            $user,
            $maxTimeGap,
            $maxDistanceKm,
            $minClusterSize,
            (bool)$homeAware,
            $home,
            $thresholds,
            $fromScratch,
            $recentCutoffDays,
            false,
            $includeGroupFolders,
            $includeSharedImages,
            $fromTs,
            $toTs,
            $progressCallback,
            $splitCallback,
            $mergeAdjacent,
        );
        $stats = $this->imageFetcher->getLastFetchStats();
        $output->writeln(sprintf(
            '<info>Image sources:</info> total=%d (home=%d, groupOnly=%d, sharedOnly=%d)',
            $stats['total'] ?? 0,
            $stats['home'] ?? 0,
            $stats['group'] ?? 0,
            $stats['shared'] ?? 0,
        ));

        if (isset($result['error'])) {
            $output->writeln('<error>' . $result['error'] . '</error>');
            return Command::FAILURE;
        }
        $prunedEmpty = (int)($result['prunedEmptyAlbums'] ?? 0);
        if ($prunedEmpty > 0) {
            $output->writeln(sprintf('<info>Removed %d empty journey album%s.</info>', $prunedEmpty, $prunedEmpty === 1 ? '' : 's'));
        }
        $output->writeln('Found ' . $result['clustersCreated'] . ' clusters. Creating albums...\n');
        if (!$clustersPrinted) {
            foreach ($result['clusters'] as $cluster) {
                $output->writeln(sprintf(
                    "Created album '%s' with %d images." . ($cluster['location'] ? " (Location: %s)" : ""),
                    $cluster['albumName'],
                    $cluster['imageCount'],
                    $cluster['location'] ?? ''
                ));
            }
        }
        $output->writeln('All clusters processed.');
        return Command::SUCCESS;
    }
}
