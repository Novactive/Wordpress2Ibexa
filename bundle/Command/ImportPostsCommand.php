<?php

declare(strict_types=1);

namespace Almaviacx\Bundle\Ibexa\WordPress\Command;

use Almaviacx\Bundle\Ibexa\WordPress\Service\PostService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ImportPostsCommand extends Command
{
    private PostService $postService;

    /**
     * @required
     */
    public function setDependencies(PostService $postService)
    {
        $this->postService = $postService;
    }

    protected function configure()
    {
        $this
            ->setName('wordpress:ibexa:import:post')
            ->addOption(
                'per-page',
                null,
                InputOption::VALUE_OPTIONAL,
                'Per page'
            )
            ->addOption(
                'page',
                null,
                InputOption::VALUE_OPTIONAL,
                'Page from'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Rolls back any database changes'
            )
            ->setDescription('Import Blog Posts from wordpress to ibexa content');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $perPage = (int) ($input->getOption('per-page'));
        $perPage = $perPage > 0? $perPage: null;
        $page = (int) ($input->getOption('page'));
        $page = $page > 0? $page: null;
        $count = $this->postService->import($perPage, $page);
        $io->info("Post imported => $count");
        $io->success('Done');
        return Command::SUCCESS;
    }
}
//php bin/console w:i:im -vv