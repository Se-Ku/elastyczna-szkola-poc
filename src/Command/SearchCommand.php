<?php

declare(strict_types=1);

namespace App\Command;

use App\DTO\SearchQuery;
use App\Search\SearchInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Zapewnia możliwość interaktywnego testowania działania dopasowywania fraz.
 */
#[AsCommand(
    name: 'app:search',
    description: 'Wyszukuje dane w systemie korzystając ze wstrzykniętego dostawcy (Provider).'
)]
class SearchCommand extends Command
{
    public function __construct(
        private readonly SearchInterface $searchService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'phrase',
            InputArgument::OPTIONAL,
            'Szukana fraza. Jeśli brak, komenda uruchomi się w trybie interaktywnym.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string|null $phrase */
        $phrase = $input->getArgument('phrase');

        // 1. Tryb pojedynczego wywołania (z podanym argumentem)
        if ($phrase !== null && trim($phrase) !== '') {
            $this->performSearch($io, trim($phrase));
            return Command::SUCCESS;
        }

        // 2. Tryb interaktywny (brak argumentu)
        $io->title('Tryb interaktywny wyszukiwania');
        $io->comment('Wpisz frazę i naciśnij Enter. Aby zakończyć, zostaw puste pole lub wpisz "exit".');

        while (true) {
            $phrase = $io->ask('Szukana fraza');

            // Warunek wyjścia z pętli
            if ($phrase === null || trim($phrase) === '' || strtolower(trim($phrase)) === 'exit') {
                $io->note('Zakończono tryb interaktywny.');
                break;
            }

            $this->performSearch($io, trim($phrase));
            $io->newLine();
        }

        return Command::SUCCESS;
    }

    private function performSearch(SymfonyStyle $io, string $phrase): void
    {
        $io->section("Wyniki dla: \"{$phrase}\"");

        // Zwracamy 3 rezultaty, aby mieć wgląd w działanie mechanizmu. Można zwrócić tylko 1 (najlepsze dopasowanie).
        $testLimit = 3;

        $query = new SearchQuery($phrase, $testLimit);
        $result = $this->searchService->search($query);

        if ($result->total === 0) {
            $io->warning('Brak wyników.');
            return;
        }

        $io->writeln(sprintf('Znaleziono wyników: <info>%d</info>. Prezentujemy do <comment>%d</comment> najlepszych.', $result->total, $testLimit));


        foreach ($result->items as $item) {
            $io->text(sprintf(
                '• <comment>%s</comment> (%s)',
                $item['official_name'] ?? 'Brak',
                $item['score']
            ));
        }
    }
}
