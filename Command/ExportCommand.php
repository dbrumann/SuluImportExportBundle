<?php

declare(strict_types=1);

/*
 * This file is part of TheCadien/SuluImportExportBundle.
 *
 * (c) Oliver Kossin
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace TheCadien\Bundle\SuluImportExportBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use TheCadien\Bundle\SuluImportExportBundle\Helper\ImportExportDefaultMap;

class ExportCommand extends Command
{
    /**
     * @var InputInterface
     */
    private $input;
    /**
     * @var OutputInterface
     */
    private $output;
    /**
     * @var ProgressBar
     */
    private $progressBar;
    private $databaseHost;
    private $databaseUser;
    private $databaseName;
    private $databasePassword;
    private $exportDirectory;
    private $uploadsDirectory;

    public function __construct(
        string $databaseHost,
        string $databaseName,
        string $databaseUser,
        string $databasePassword,
        string $exportDirectory,
        string $uploadsDirectory
    ) {
        parent::__construct();
        $this->databaseHost = $databaseHost;
        $this->databaseUser = $databaseUser;
        $this->databaseName = $databaseName;
        $this->databasePassword = $databasePassword;
        $this->exportDirectory = $exportDirectory;
        $this->uploadsDirectory = ($uploadsDirectory) ?: ImportExportDefaultMap::SULU_DEFAULT_MEDIA_PATH;
    }

    protected function configure()
    {
        $this
            ->setName('sulu:export')
            ->setDescription('Exports all Sulu contents (PHPCR, database, uploads) to the web directory.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->progressBar = new ProgressBar($this->output, 3);
        $this->progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% <info>%message%</info>');
        $this->exportPHPCR();
        $this->exportDatabase();
        $this->exportUploads();
        $this->progressBar->finish();
        $this->output->writeln(
            PHP_EOL . '<info>Successfully exported contents.</info>'
        );
    }

    private function exportPHPCR()
    {
        $this->progressBar->setMessage('Exporting PHPCR repository...');
        $this->executeCommand(
            'doctrine:phpcr:workspace:export',
            [
                '-p' => '/cmf',
                'filename' => $this->exportDirectory . \DIRECTORY_SEPARATOR . ImportExportDefaultMap::FILENAME_PHPCR,
            ]
        );
        $this->progressBar->advance();
    }

    private function exportDatabase()
    {
        $this->progressBar->setMessage('Exporting database...');
        $command =
            "mysqldump -h {$this->databaseHost} -u " . escapeshellarg($this->databaseUser) .
            ($this->databasePassword ? ' -p' . escapeshellarg($this->databasePassword) : '') .
            ' ' . escapeshellarg($this->databaseName) . ' > ' . $this->exportDirectory . \DIRECTORY_SEPARATOR . ImportExportDefaultMap::FILENAME_SQL;

        $process = Process::fromShellCommandline($command);
        $process->run();
        $this->progressBar->advance();
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function exportUploads()
    {
        $this->progressBar->setMessage('Exporting uploads...');
        // Directory path with new Symfony directory structure - i.e. var/uploads.
        $process = Process::fromShellCommandline(
            'tar cvf ' . $this->exportDirectory . \DIRECTORY_SEPARATOR . ImportExportDefaultMap::FILENAME_UPLOADS . " {$this->uploadsDirectory}"
        );
        $process->setTimeout(300);
        $process->run();
        $this->progressBar->advance();
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function executeCommand($cmd, array $params)
    {
        $command = $this->getApplication()->find($cmd);
        $command->run(
            new ArrayInput(
                ['command' => $cmd] + $params
            ),
            new NullOutput()
        );
    }
}
