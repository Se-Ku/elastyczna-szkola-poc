<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ElasticsearchManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:elastic:init',
    description: 'Inicjuje indeks w Elasticsearch i wypełnia go danymi początkowymi (PoC).'
)]
class ElasticsearchInitCommand extends Command
{
    public function __construct(
        private readonly ElasticsearchManager $manager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->info('Resetowanie indeksu i tworzenie mapowania...');
        $this->manager->initializeIndex();

        $io->info('Ładowanie danych startowych...');
        $this->manager->seedData();

        $io->success('Elasticsearch został poprawnie zainicjowany!');

        return Command::SUCCESS;
    }
}
