<?php

namespace Drutiny\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 *
 */
class PluginListCommand extends Command
{
    protected $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct();
    }

  /**
   * @inheritdoc
   */
    protected function configure()
    {
        $this
        ->setName('plugin:list')
        ->setDescription('List all available plugins.');
    }

  /**
   * @inheritdoc
   */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $rows = [];
        foreach ($this->container->findTaggedServiceIds('plugin') as $id => $info) {
            $plugin = $this->container->get($id);
            $rows[] = [$plugin->getName(), $plugin->load() ? 'Installed' : 'Uninstalled'];
        }

        $io->table(['Namespace', 'Status'], $rows);
        return 0;
    }
}