<?php

namespace OCA\Journeys\Command;

use OCP\IUserManager;
use OCA\Journeys\Service\AlbumCreator;
use OCP\User\IUser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;

class RemoveAllAlbumsCommand extends Command
{
    protected static $defaultName = 'journeys:remove-all-albums';

    /** @var AlbumCreator */
    private $albumCreator;
    /** @var IUserManager */
    private $userManager;

    public function __construct(AlbumCreator $albumCreator, IUserManager $userManager)
    {
        parent::__construct();
        $this->albumCreator = $albumCreator;
        $this->userManager = $userManager;
    }

    protected function configure()
    {
        $this
            ->setDescription('Removes all albums for a specific user using the Journeys album manager logic (not system tags).')
            ->addArgument('user', InputArgument::REQUIRED, 'The user ID for which to remove all albums');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $userId = $input->getArgument('user');
        $io->title("Removing all albums for user '{$userId}' using Journeys album manager...");

        $user = $this->userManager->get($userId);
        if (!$user) {
            $io->error("User '{$userId}' does not exist.");
            return 1;
        }

        $deleted = $this->albumCreator->purgeAllAlbums($userId);
        // Also reset the latest tracked end for cluster albums
        $this->albumCreator->resetLatestClusterEnd($userId);
        $io->success("User <info>{$userId}</info>: Deleted <comment>{$deleted}</comment> albums and reset latest cluster end");
        return 0;
    }
}
