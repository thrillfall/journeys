<?php
namespace OCA\Journeys\Command;

use OCA\Journeys\Service\ImageFetcher;
use OCA\Journeys\Service\Clusterer;
use OCA\Journeys\Model\Image;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListClustersCommand extends Command {
    protected static $defaultName = 'journeys:list-clusters';

    protected function configure() {
        $this
            ->setDescription('List found clusters (journeys) for a user with image count and time frame')
            ->addArgument('user', InputArgument::REQUIRED, 'User ID')
            ->addArgument('maxTimeGap', InputArgument::OPTIONAL, 'Max allowed time gap in seconds', 86400)
            ->addArgument('maxDistanceKm', InputArgument::OPTIONAL, 'Max allowed distance in kilometers', 100.0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $user = $input->getArgument('user');
        $maxTimeGap = (int)$input->getArgument('maxTimeGap');
        $maxDistanceKm = (float)$input->getArgument('maxDistanceKm');

        // Fetch images
        $fetcher = new ImageFetcher();
        $images = $fetcher->fetchImagesForUser($user);

        // Sort by datetaken
        usort($images, function($a, $b) {
            return strtotime($a->datetaken) <=> strtotime($b->datetaken);
        });

        // Cluster
        $clusterer = new Clusterer();
        $clusters = $clusterer->clusterImages($images, $maxTimeGap, $maxDistanceKm);

        // Output
        $output->writeln("Found " . count($clusters) . " clusters:\n");
        foreach ($clusters as $i => $cluster) {
            $count = count($cluster);
            $start = $cluster[0]->datetaken;
            $end = $cluster[$count-1]->datetaken;
            $output->writeln(sprintf(
                "Cluster %d: %d images | %s to %s",
                $i+1, $count, $start, $end
            ));
        }
        return Command::SUCCESS;
    }
}
