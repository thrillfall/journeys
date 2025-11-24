<?php
namespace OCA\Journeys\Command\Traits;

use OCA\Journeys\Model\ClusterVideoSelection;
use Symfony\Component\Console\Output\OutputInterface;

trait FaceBoostLoggingTrait {
    private function logFaceBoost(OutputInterface $output, ClusterVideoSelection $selection): void {
        if ($selection->selectedCount <= 0) {
            $output->writeln('<comment>No suitable images found for this cluster.</comment>');
            return;
        }

        if ($selection->boostFaces) {
            $output->writeln(sprintf(
                '<info>Face boost enabled:</info> %d of %d selected images contain faces.',
                $selection->facesSelected,
                $selection->selectedCount,
            ));
        } else {
            $output->writeln(sprintf(
                '<comment>Face boost disabled:</comment> %d of %d selected images contain faces.',
                $selection->facesSelected,
                $selection->selectedCount,
            ));
        }
    }
}
