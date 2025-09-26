<?php
namespace OCA\Journeys\Command;

use OCA\Journeys\Service\AlbumCreator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListClustersCommand extends Command {
    protected static $defaultName = 'journeys:list-clusters';

    protected function configure(): void {
        $this
            ->setDescription('List tracked clusters (Photos album id and name) for a user')
            ->addArgument('user', InputArgument::REQUIRED, 'User ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $user = (string)$input->getArgument('user');

        /** @var AlbumCreator $albumCreator */
        $albumCreator = \OC::$server->query(AlbumCreator::class);
        $tracked = array_values($albumCreator->getTrackedClusters($user));

        if (empty($tracked)) {
            $output->writeln('<comment>No tracked clusters found.</comment>');
            return Command::SUCCESS;
        }

        // header
        $output->writeln('AlbumID\tName');
        foreach ($tracked as $row) {
            $id = (string)($row['album_id'] ?? '');
            $name = (string)($row['name'] ?? '');
            $output->writeln(sprintf('%s\t%s', $id, $name));
        }

        return Command::SUCCESS;
    }
}
