<?php
namespace OCA\Journeys\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\Journeys\Service\ImageFetcher;

class FetchUserImagesCommand extends Command {
    protected static $defaultName = 'journeys:fetch-user-images';


    protected function configure() {
        $this
            ->setDescription('Fetch all images indexed by Memories for a given user, with location and time_taken')
            ->addArgument('user', InputArgument::REQUIRED, 'Username to fetch images for');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $user = $input->getArgument('user');
        $fetcher = new ImageFetcher();
        $rows = $fetcher->fetchImagesForUser($user);
        foreach ($rows as $image) {
            $output->writeln(json_encode([
                'fileid' => $image->fileid,
                'path' => $image->path,
                'datetaken' => $image->datetaken,
                'lat' => $image->lat,
                'lon' => $image->lon,
            ]));
        }
        return Command::SUCCESS;
    }
}
