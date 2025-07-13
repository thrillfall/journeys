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
use OCA\Journeys\Service\ClusteringManager;

class ClusterAndCreateAlbumsCommand extends Command {
    protected static $defaultName = 'journeys:cluster-create-albums';

    private ClusteringManager $clusteringManager;
    private AlbumMapper $albumMapper;

    public function __construct(
        ClusteringManager $clusteringManager,
        AlbumMapper $albumMapper
    ) {
        parent::__construct(static::$defaultName);
        $this->clusteringManager = $clusteringManager;
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

        // Delegate clustering and album creation to ClusteringManager
        $result = $this->clusteringManager->clusterForUser($user, $maxTimeGap, $maxDistanceKm);

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
