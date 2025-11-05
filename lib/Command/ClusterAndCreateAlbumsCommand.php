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
    protected static $defaultName = 'journeys:cluster-create-albums';

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
            ->setDescription('Clusters images by location and creates albums in the Photos app (incremental by default, home-aware enabled by default).')
            ->addArgument('user', InputArgument::REQUIRED, 'The ID of the user for whom to cluster images and create albums.')
            ->addArgument('maxTimeGap', InputArgument::OPTIONAL, 'Max allowed time gap in hours (if omitted, use UI setting)')
            ->addArgument('maxDistanceKm', InputArgument::OPTIONAL, 'Max allowed distance in kilometers (if omitted, use UI setting)')
            ->addOption('no-home-aware', null, InputOption::VALUE_NONE, 'Disable home-aware clustering')
            ->addOption('from-scratch', null, InputOption::VALUE_NONE, 'Recluster all images from scratch (purges previously created cluster albums)')
            ->addOption('min-cluster-size', null, InputOption::VALUE_REQUIRED, 'Minimum images per cluster (if omitted, use UI setting)')
            ->addOption('home-lat', null, InputOption::VALUE_REQUIRED, 'Home latitude')
            ->addOption('home-lon', null, InputOption::VALUE_REQUIRED, 'Home longitude')
            ->addOption('home-radius', null, InputOption::VALUE_REQUIRED, 'Home radius in km (default: 50)', 50)
            ->addOption('near-time-gap', null, InputOption::VALUE_REQUIRED, 'Near-home max time gap in hours (if omitted, use UI setting)')
            ->addOption('near-distance-km', null, InputOption::VALUE_REQUIRED, 'Near-home max distance between consecutive photos in km (if omitted, use UI setting)')
            ->addOption('away-time-gap', null, InputOption::VALUE_REQUIRED, 'Away-from-home max time gap in hours (if omitted, use UI setting)')
            ->addOption('away-distance-km', null, InputOption::VALUE_REQUIRED, 'Away-from-home max distance between consecutive photos in km (if omitted, use UI setting)')
            ->addOption('recent-cutoff-days', null, InputOption::VALUE_REQUIRED, 'Skip clusters whose last image is within the past N days (default: 2, 0 disables)', 2)
            ->addOption('include-group-folders', null, InputOption::VALUE_NONE, 'Include images from Group Folders and other mounts (if omitted, use UI setting)');
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
        if ($rawDistanceKm !== null && $rawDistanceKm !== '') {
            $maxDistanceKm = (float)$rawDistanceKm;
        } elseif ($cfg) {
            $maxDistanceKm = (float)$cfg->getUserValue($user, 'journeys', 'maxDistanceKm', 100.0);
        } else {
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
                $images = $this->imageFetcher->fetchImagesForUser($user, $includeGroupFolders);
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
            $output->writeln(sprintf('<info>Effective settings:</info> homeAware=%s, includeGroupFolders=%s, minClusterSize=%d, recentCutoffDays=%d', $homeAware ? 'true' : 'false', $includeGroupFolders ? 'true' : 'false', $minClusterSize, $recentCutoffDays));
            $output->writeln(sprintf('  near: timeGap=%ds (%.1fh), distance=%.2fkm', (int)$thresholds['near']['timeGap'], (int)$thresholds['near']['timeGap']/3600.0, (float)$thresholds['near']['distanceKm']));
            $output->writeln(sprintf('  away: timeGap=%ds (%.1fh), distance=%.2fkm', (int)$thresholds['away']['timeGap'], (int)$thresholds['away']['timeGap']/3600.0, (float)$thresholds['away']['distanceKm']));
        } else {
            $output->writeln(sprintf('<info>Effective settings:</info> homeAware=%s, includeGroupFolders=%s, minClusterSize=%d, recentCutoffDays=%d', 'false', $includeGroupFolders ? 'true' : 'false', $minClusterSize, $recentCutoffDays));
            $output->writeln(sprintf('  global: timeGap=%ds (%.1fh), distance=%.2fkm', (int)$maxTimeGap, (int)$maxTimeGap/3600.0, (float)$maxDistanceKm));
        }
        // Delegate clustering and album creation to ClusteringManager (home-aware optional)
        $result = $this->clusteringManager->clusterForUser($user, $maxTimeGap, $maxDistanceKm, $minClusterSize, (bool)$homeAware, $home, $thresholds, $fromScratch, $recentCutoffDays, false, $includeGroupFolders);

        if (isset($result['error'])) {
            $output->writeln('<error>' . $result['error'] . '</error>');
            return Command::FAILURE;
        }
        $output->writeln('Found ' . $result['clustersCreated'] . ' clusters. Creating albums...\n');
        foreach ($result['clusters'] as $cluster) {
            $output->writeln(sprintf(
                "Created album '%s' with %d images." . ($cluster['location'] ? " (Location: %s)" : ""),
                $cluster['albumName'],
                $cluster['imageCount'],
                $cluster['location'] ?? ''
            ));
        }
        $output->writeln('All clusters processed.');
        return Command::SUCCESS;
    }
}
