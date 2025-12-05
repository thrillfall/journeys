<?php
namespace OCA\Journeys\Command;

use OCA\Journeys\Service\AlbumCreator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
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

        $clusterRows = array_map(static function (array $row): array {
            $id = isset($row['album_id']) ? (string)(int)$row['album_id'] : '';
            $name = (string)($row['name'] ?? '');
            return [$id, $name, 'Journey cluster'];
        }, $tracked);

        $trackedIds = array_map(static function (array $row): int {
            return isset($row['album_id']) ? (int)$row['album_id'] : 0;
        }, $tracked);

        $allAlbums = $albumCreator->getAllAlbumsForUser($user);
        $manualAlbums = array_values(array_filter($allAlbums, static function (array $album) use ($trackedIds): bool {
            $id = isset($album['album_id']) ? (int)$album['album_id'] : 0;
            return $id > 0 && !in_array($id, $trackedIds, true);
        }));

        $manualRows = array_map(static function (array $album): array {
            $id = isset($album['album_id']) ? (string)(int)$album['album_id'] : '';
            $name = (string)($album['name'] ?? '');
            return [$id, $name, 'Manual album'];
        }, $manualAlbums);

        $this->renderAlbumSection($output, 'Clusters', $clusterRows, '<comment>No tracked clusters found.</comment>');
        $this->renderAlbumSection($output, 'Manual albums', $manualRows, '<comment>No manual albums found.</comment>');

        return Command::SUCCESS;
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function renderAlbumSection(OutputInterface $output, string $title, array $rows, string $emptyMessage): void {
        $output->writeln('');
        $output->writeln(sprintf('<info>%s</info>', $title));

        if (empty($rows)) {
            $output->writeln($emptyMessage);
            return;
        }

        $table = new Table($output);
        $table
            ->setHeaders(['Album ID', 'Name', 'Type'])
            ->setRows($rows)
            ->render();
    }
}
