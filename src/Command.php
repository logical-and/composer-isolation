<?php

namespace Ox6d617474\Isolate;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class Command extends BaseCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('isolate');
        $this->setDescription('Isolate dependencies');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->getComposer()->getEventDispatcher()->dispatch('__isolate-dependencies');
    }
}
