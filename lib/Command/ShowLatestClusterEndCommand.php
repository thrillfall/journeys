<?php
namespace OCA\Journeys\Command;

use OCA\Journeys\Service\AlbumCreator;
use OCA\Journeys\Service\ImageFetcher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ShowLatestClusterEndCommand extends Command {
    protected static $defaultName = 'journeys:latest-end';

    public function __construct(
        private readonly AlbumCreator $albumCreator,
        private readonly ImageFetcher $imageFetcher,
    ) {
        parent::__construct(static::$defaultName);
    }

    protected function configure(): void {
        $this
            ->setDescription('Show the latest tracked end_dt for clusterer-created albums (per user).')
            ->addArgument('user', InputArgument::REQUIRED, 'User ID')
            ->addOption('derive-fallback', null, InputOption::VALUE_NONE, 'If no end_dt is recorded yet, derive a latest end from tracked albums using current images');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $user = (string)$input->getArgument('user');
        $latest = $this->albumCreator->getLatestClusterEnd($user);
        if ($latest !== null) {
            $output->writeln(sprintf('<info>Latest end_dt:</info> %s (timestamp=%d)', $latest->format('c'), $latest->getTimestamp()));
            return Command::SUCCESS;
        }
        $hasTracked = $this->albumCreator->hasTrackedAlbums($user);
        if ($hasTracked && $input->getOption('derive-fallback')) {
            $images = $this->imageFetcher->fetchImagesForUser($user);
            $derived = $this->albumCreator->deriveLatestEndFromTracked($user, $images);
            if ($derived !== null) {
                $output->writeln(sprintf('<comment>No end_dt recorded, derived latest from tracked albums:</comment> %s (timestamp=%d)', $derived->format('c'), $derived->getTimestamp()));
                return Command::SUCCESS;
            }
        }
        if ($hasTracked) {
            $output->writeln('<comment>No end_dt recorded in tracking table yet for this user.</comment>');
        } else {
            $output->writeln('<comment>No tracked cluster albums found for this user.</comment>');
        }
        return Command::SUCCESS;
    }
}
