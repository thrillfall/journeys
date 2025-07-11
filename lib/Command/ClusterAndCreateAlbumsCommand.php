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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCP\IUserManager;
use OCP\Files\IRootFolder;
use OCA\Photos\Album\AlbumMapper;

class ClusterAndCreateAlbumsCommand extends Command {
    protected static $defaultName = 'journeys:cluster-create-albums';

    private AlbumCreator $albumCreator;
    private Clusterer $clusterer;
    private ImageFetcher $imageFetcher;
    private ClusterLocationResolver $locationResolver;
    private AlbumMapper $albumMapper;

    public function __construct(
        AlbumCreator $albumCreator,
        Clusterer $clusterer,
        ImageFetcher $imageFetcher,
        ClusterLocationResolver $locationResolver,
        AlbumMapper $albumMapper
    ) {
        parent::__construct(static::$defaultName);
        $this->albumCreator = $albumCreator;
        $this->clusterer = $clusterer;
        $this->imageFetcher = $imageFetcher;
        $this->locationResolver = $locationResolver;
        $this->albumMapper = $albumMapper;
    }

    protected function configure(): void {
        $this
            ->setDescription('Clusters images by location and creates albums in the Photos app.')
            ->addArgument('user', InputArgument::REQUIRED, 'The ID of the user for whom to cluster images and create albums.')
            ->addArgument('maxTimeGap', InputArgument::OPTIONAL, 'Max allowed time gap in seconds', 86400)
            ->addArgument('maxDistanceKm', InputArgument::OPTIONAL, 'Max allowed distance in kilometers', 100.0);
    }

    private function deleteAllAlbums(string $userId, OutputInterface $output): void {
        $output->writeln("Deleting clusterer-created albums...");
        $albums = $this->albumMapper->getForUser($userId);
        foreach ($albums as $album) {
            if (strpos($album->getTitle(), AlbumCreator::CLUSTERER_MARKER) !== false) {
                $this->albumMapper->delete($album->getId());
                $output->writeln(sprintf("  Deleted album: %s", $album->getTitle()));
            }
        }
        $output->writeln("Finished deleting clusterer-created albums.");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $user = $input->getArgument('user');
        $maxTimeGap = (int)$input->getArgument('maxTimeGap');
        $maxDistanceKm = (float)$input->getArgument('maxDistanceKm');

        // Delete existing albums
        $this->deleteAllAlbums($user, $output);

        // Fetch images
        $images = $this->imageFetcher->fetchImagesForUser($user);
        if (empty($images)) {
            $output->writeln('<error>No images found for user.</error>');
            return Command::FAILURE;
        }

        // Sort by datetaken
        usort($images, function($a, $b) {
            return strtotime($a->datetaken) <=> strtotime($b->datetaken);
        });

        // Interpolate missing locations (6-hour = 21600 seconds max gap)
        $images = ImageLocationInterpolator::interpolate($images, 21600);

        // Cluster
        $clusters = $this->clusterer->clusterImages($images, $maxTimeGap, $maxDistanceKm);
        $output->writeln("Found " . count($clusters) . " clusters. Creating albums...\n");

        foreach ($clusters as $i => $cluster) {
            $count = count($cluster);
            $start = $cluster[0]->datetaken;
            $end = $cluster[$count-1]->datetaken;
            $location = $this->locationResolver->resolveClusterLocation($cluster, true);
            if ($location) {
                $albumName = sprintf('%s (%s to %s)', $location, $start, $end);
            } else {
                $albumName = sprintf('Journey %d (%s to %s)', $i+1, $start, $end);
            }
            $this->albumCreator->createAlbumWithImages($user, $albumName, $cluster, $location ?? '');
            $output->writeln(sprintf(
                "Created album '%s' with %d images.",
                $albumName, $count
            ));
        }
        $output->writeln("All clusters processed.");
        return Command::SUCCESS;
    }
}
