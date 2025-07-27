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
            ->setDescription('Clusters images by location and creates albums in the Photos app.')
            ->addArgument('user', InputArgument::REQUIRED, 'The ID of the user for whom to cluster images and create albums.')
            ->addArgument('maxTimeGap', InputArgument::OPTIONAL, 'Max allowed time gap in hours', 24)
            ->addArgument('maxDistanceKm', InputArgument::OPTIONAL, 'Max allowed distance in kilometers', 100.0)
            ->addArgument('minClusterSize', InputArgument::OPTIONAL, 'Minimum images per cluster', 3)
            ->addOption('home-aware', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Detect and output user home location');
    }


    protected function execute(InputInterface $input, OutputInterface $output): int {
        $user = $input->getArgument('user');
        $maxTimeGap = (int)$input->getArgument('maxTimeGap') * 3600; // convert hours to seconds
        $maxDistanceKm = (float)$input->getArgument('maxDistanceKm');
        $minClusterSize = (int)$input->getArgument('minClusterSize');
        $homeAware = $input->getOption('home-aware');

        if ($homeAware) {
            // Fetch images and output detected home location
            $images = $this->imageFetcher->fetchImagesForUser($user);
            $home = $this->clusteringManager->detectHomeLocation($images);
            if ($home) {
                $output->writeln(sprintf(
                    '<info>Detected Home Location:</info> lat=%.5f, lon=%.5f%s',
                    $home['lat'], $home['lon'],
                    $home['name'] ? ", name={$home['name']}" : ''
                ));
            } else {
                $output->writeln('<comment>Could not determine home location (not enough geotagged images).</comment>');
            }
        }

        // Delegate clustering and album creation to ClusteringManager
        $result = $this->clusteringManager->clusterForUser($user, $maxTimeGap, $maxDistanceKm, $minClusterSize);

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
