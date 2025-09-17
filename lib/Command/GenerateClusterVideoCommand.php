<?php
namespace OCA\Journeys\Command;

use OCA\Journeys\Service\ImageFetcher;
use OCA\Journeys\Service\Clusterer;
use OCA\Journeys\Service\VideoStorySelector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateClusterVideoCommand extends Command {
    protected static $defaultName = 'journeys:generate-cluster-video';

    private ImageFetcher $imageFetcher;
    private Clusterer $clusterer;
    private VideoStorySelector $selector;

    public function __construct(ImageFetcher $imageFetcher, Clusterer $clusterer, VideoStorySelector $selector)
    {
        parent::__construct(static::$defaultName);
        $this->imageFetcher = $imageFetcher;
        $this->clusterer = $clusterer;
        $this->selector = $selector;
    }

    protected function configure(): void {
        $this
            ->setDescription('Generate a video playlist for a specific cluster (images only for now).')
            ->addArgument('user', InputArgument::REQUIRED, 'User ID')
            ->addArgument('cluster', InputArgument::REQUIRED, 'Cluster number (1-based, as listed by journeys:list-clusters)')
            ->addOption('min-gap-seconds', null, InputOption::VALUE_REQUIRED, 'Minimum seconds between selected images (burst dedupe)', 5)
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output path for M3U playlist (defaults to stdout)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $user = (string)$input->getArgument('user');
        $clusterIndex = max(1, (int)$input->getArgument('cluster')) - 1;
        $minGap = max(0, (int)$input->getOption('min-gap-seconds'));
        $outPath = $input->getOption('output');

        // 1) Fetch and sort
        $images = $this->imageFetcher->fetchImagesForUser($user);
        if (empty($images)) {
            $output->writeln('<comment>No images found for user.</comment>');
            return Command::SUCCESS;
        }
        usort($images, fn($a,$b) => strtotime($a->datetaken) <=> strtotime($b->datetaken));

        // 2) Cluster using existing thresholds (time gap 24h, distance 50km like defaults)
        $clusters = $this->clusterer->clusterImages($images, 24*3600, 50.0);
        if ($clusterIndex < 0 || $clusterIndex >= count($clusters)) {
            $output->writeln(sprintf('<error>Cluster %d not found. Found %d clusters.</error>', $clusterIndex+1, count($clusters)));
            return Command::FAILURE;
        }
        $cluster = $clusters[$clusterIndex];

        // 3) Select story images (faces preferred, dedupe bursts)
        $selected = $this->selector->selectImages($user, $cluster, $minGap);
        if (empty($selected)) {
            $output->writeln('<comment>No suitable images found for this cluster.</comment>');
            return Command::SUCCESS;
        }

        // 4) Build simple M3U playlist using absolute Nextcloud paths (relative to user root)
        $lines = ["#EXTM3U"]; // keep it simple without durations for now
        foreach ($selected as $img) {
            // The Image model carries a path, usually starting with files/
            $path = $img->path;
            if (strpos($path, 'files/') === 0) {
                $path = substr($path, 6);
            }
            $lines[] = $path;
        }
        $content = implode("\n", $lines) . "\n";

        if ($outPath) {
            // Write to file
            file_put_contents($outPath, $content);
            $output->writeln(sprintf('<info>Playlist written:</info> %s (%d images)', $outPath, count($selected)));
        } else {
            // Print to stdout
            $output->writeln($content);
            $output->writeln(sprintf("<info>Selected %d images.</info>", count($selected)));
        }

        return Command::SUCCESS;
    }
}
