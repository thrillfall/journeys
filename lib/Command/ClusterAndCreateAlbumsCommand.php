<?php
namespace OCA\Journeys\Command;

use OCA\Journeys\Service\ImageFetcher;
use OCA\Journeys\Service\Clusterer;
use OCA\Journeys\Service\AlbumCreator; // ensure import for marker constant

use OCA\Journeys\Service\ClusterLocationResolver;
use OCA\Journeys\Service\SimplePlaceResolver;
use OCA\Journeys\Service\ImageLocationInterpolator; // ADDED
use OCP\IDBConnection;


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

    public function __construct(
        ClusteringManager $clusteringManager,
        AlbumMapper $albumMapper,
        ImageFetcher $imageFetcher
    ) {
        parent::__construct(static::$defaultName);
        $this->clusteringManager = $clusteringManager;
        $this->albumMapper = $albumMapper;
        $this->imageFetcher = $imageFetcher;
    }


    protected function configure(): void {
        $this
            ->setDescription('Clusters images by location and creates albums in the Photos app (incremental by default).')
            ->addArgument('user', InputArgument::REQUIRED, 'The ID of the user for whom to cluster images and create albums.')
            ->addArgument('maxTimeGap', InputArgument::OPTIONAL, 'Max allowed time gap in hours', 24)
            ->addArgument('maxDistanceKm', InputArgument::OPTIONAL, 'Max allowed distance in kilometers (default: 50.0)', 50.0)
            ->addArgument('minClusterSize', InputArgument::OPTIONAL, 'Minimum images per cluster', 3)
            ->addOption('home-aware', null, InputOption::VALUE_NONE, 'Enable home-aware clustering (uses detected or provided home)')
            ->addOption('from-scratch', null, InputOption::VALUE_NONE, 'Recluster all images from scratch (purges previously created cluster albums)')
            ->addOption('home-lat', null, InputOption::VALUE_REQUIRED, 'Home latitude')
            ->addOption('home-lon', null, InputOption::VALUE_REQUIRED, 'Home longitude')
            ->addOption('home-radius', null, InputOption::VALUE_REQUIRED, 'Home radius in km (default: 50)', 50)
            ->addOption('near-time-gap', null, InputOption::VALUE_REQUIRED, 'Near-home max time gap in hours (default: 6)', 6)
            ->addOption('near-distance-km', null, InputOption::VALUE_REQUIRED, 'Near-home max distance between consecutive photos in km (default: 3)', 3)
            ->addOption('away-time-gap', null, InputOption::VALUE_REQUIRED, 'Away-from-home max time gap in hours (default: 36)', 36)
            ->addOption('away-distance-km', null, InputOption::VALUE_REQUIRED, 'Away-from-home max distance between consecutive photos in km (default: 50)', 50)
            ->addOption('recent-cutoff-days', null, InputOption::VALUE_REQUIRED, 'Skip clusters whose last image is within the past N days (default: 5, 0 disables)', 5);
    }


    protected function execute(InputInterface $input, OutputInterface $output): int {
        $user = $input->getArgument('user');
        $maxTimeGap = (int)$input->getArgument('maxTimeGap') * 3600; // convert hours to seconds
        $maxDistanceKm = (float)$input->getArgument('maxDistanceKm');
        $minClusterSize = (int)$input->getArgument('minClusterSize');
        $homeAware = $input->getOption('home-aware');
        $homeLat = $input->getOption('home-lat');
        $homeLon = $input->getOption('home-lon');
        $homeRadius = (float)$input->getOption('home-radius');
        $nearTimeGap = (int)$input->getOption('near-time-gap') * 3600;
        $nearDistanceKm = (float)$input->getOption('near-distance-km');
        $awayTimeGap = (int)$input->getOption('away-time-gap') * 3600;
        $awayDistanceKm = (float)$input->getOption('away-distance-km');
        $fromScratch = (bool)$input->getOption('from-scratch');
        $recentCutoffDays = max(0, (int)$input->getOption('recent-cutoff-days'));

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
                // Fetch images and output detected home location
                $images = $this->imageFetcher->fetchImagesForUser($user);
                $detected = $this->clusteringManager->detectHomeLocation($images);
                if ($detected) {
                    $home = [ 'lat' => $detected['lat'], 'lon' => $detected['lon'], 'radiusKm' => $homeRadius, 'name' => $detected['name'] ?? null ];
                    $output->writeln(sprintf(
                        '<info>Detected Home Location:</info> lat=%.5f, lon=%.5f%s',
                        $home['lat'], $home['lon'],
                        isset($home['name']) && $home['name'] ? ", name={$home['name']}" : ''
                    ));
                } else {
                    $output->writeln('<comment>Could not determine home location (not enough geotagged images). Proceeding without home-aware thresholds.</comment>');
                    $homeAware = false;
                    $thresholds = null;
                }
            }
        }

        // Delegate clustering and album creation to ClusteringManager (home-aware optional)
        $result = $this->clusteringManager->clusterForUser($user, $maxTimeGap, $maxDistanceKm, $minClusterSize, (bool)$homeAware, $home, $thresholds, $fromScratch, $recentCutoffDays);

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
