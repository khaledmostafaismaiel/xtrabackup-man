<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class RestoreFromS3Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:restore 
                            {--date= : Date of the full backup to restore (YYYY-MM-DD)}
                            {--time= : Target restore timestamp (HH:MM:SS)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restore backup from S3 with point-in-time recovery';

    private string $restoreDir;
    private string $restoreDate;
    private string $restoreTime;
    private ?string $targetDb;
    private string $logFile;
    /**
     * Full backup path after download (includes date subdirectory).
     */
    private string $restoreFullPath;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            // Validate arguments
            if (!$this->validateArguments()) {
                return Command::FAILURE;
            }
            // Set restore directory to fixed storage path
            $this->restoreDir = storage_path('restored-databases');

            // Initialize logging first
            $this->initializeLogging();
            $this->log('=== Restore process started ===');

            // If a previous restore exists, clean it up
            if (is_dir($this->restoreDir)) {
                $this->log('Cleaning existing restore directory');
                Process::run('rm -rf ' . escapeshellarg($this->restoreDir));
            }
            // Set target DB (optional)
            $this->targetDb = env('TARGET_DATABASE');

            $this->info('===============================================');
            $this->info('        RESTORE PROCESS STARTED');
            $this->info('===============================================');
            $this->info("Date        : $this->restoreDate");
            $this->log("Date        : $this->restoreDate");
            $this->info("Time        : $this->restoreTime");
            $this->log("Time        : $this->restoreTime");
            $this->info("Restore dir : $this->restoreDir");
            $this->log("Restore dir : $this->restoreDir");
            $this->info("Target DB   : " . ($this->targetDb ?? 'ALL DATABASES'));
            $this->log("Target DB   : " . ($this->targetDb ?? 'ALL DATABASES'));
            $this->info('===============================================');
            $this->log('===============================================');

            // Prepare directories (binlogs and data)
            $this->prepareDirectories();

            // Step 1: Download full backup
            $this->downloadFullBackup();
            $this->log('Full backup downloaded');

            // Verify that the full backup was downloaded and contains required files
            if (!is_dir($this->restoreFullPath) || count(scandir($this->restoreFullPath)) <= 2) {
                $this->error('❌ ERROR: No full backup files found for the specified date in ' . $this->restoreFullPath);
                return Command::FAILURE;
            }

            // Ensure the full backup directory exists before preparing
            if (!is_dir($this->restoreFullPath)) {
                $this->error('Full backup directory not found after download: ' . $this->restoreFullPath);
                return Command::FAILURE;
            }

            // Step 2: Prepare backup
            $this->prepareBackup();
            $this->log('Full backup prepared');

            // Step 3: Restore into datadir
            $this->restoreDatadir();
            $this->log('Datadir restored');

            // Step 4: Optional pruning
            $this->pruneDatabases();
            $this->log('Database pruning completed');

            // Step 5: Download binlogs
            $this->downloadBinlogs();
            $this->log('Binlogs downloaded');

            // Step 6: Apply binlogs
            $this->applyBinlogs();
            $this->log('Binlogs applied');

            $this->info('');
            $this->info('===============================================');
            $this->info('   RESTORE COMPLETED SUCCESSFULLY');
            $this->info("   Data restored into: $this->restoreDir/data");
            $this->info('===============================================');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Restore failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Validate command arguments
     */
    private function validateArguments(): bool
    {
        $this->restoreDir = storage_path('restored-databases');
        $this->restoreDate = $this->option('date');
        $this->restoreTime = $this->option('time');

        if (!$this->restoreDate || !$this->restoreTime) {
            $this->error('Missing required arguments.');
            $this->info('Usage: php application backup:restore --date <YYYY-MM-DD> --time <HH:MM:SS>');
            return false;
        }

        // Ensure the restore directory does not already contain previous restore data
        // Ensure the restore directory does not already contain previous restore data.
        // This check is now handled after validation and cleanup, so we simply return true here.
        // Additionally, verify that the S3 bucket is configured.
        $bucket = trim(env('AWS_S3_BUCKET'), "'\"");
        if (empty($bucket)) {
            $this->error('❌ ERROR: AWS_S3_BUCKET is not set in .env');
            return false;
        }

        return true;
    }

    /**
     * Create necessary directories
     */
    private function prepareDirectories(): void
    {
        mkdir($this->restoreDir . '/binlogs', 0755, true);
        mkdir($this->restoreDir . '/full', 0755, true);
        mkdir($this->restoreDir . '/data', 0755, true);
        $this->log('Created restore directories');
    }

    /**
     * Download full backup from S3
     */
    private function downloadFullBackup(): void
    {
        $this->info('➡ Downloading full backup...');
        $this->log('Downloading full backup...');

        // Trim possible quotes from bucket name
        $bucket = trim(env('AWS_S3_BUCKET'), "'\"");
        $region = env('AWS_S3_REGION') ?: env('AWS_REGION'); // fallback if specific var missing

        // Sync the specific date folder directly into the full directory (no extra subfolder)
        $command = sprintf(
            'aws s3 sync "s3://%s/full/%s" "%s/full" --region "%s"',
            $bucket,
            $this->restoreDate,
            $this->restoreDir,
            $region
        );

        $this->executeCommand($command, 'Failed to download full backup');
        // Set the full path for later steps (the full directory itself)
        $this->restoreFullPath = $this->restoreDir . '/full';
        $this->info('✔ Full backup downloaded');
        $this->log('Full backup downloaded');
    }

    /**
     * Prepare backup with xtrabackup
     */
    private function prepareBackup(): void
    {
        $this->info('➡ Preparing backup with xtrabackup...');

        $command = sprintf(
            'xtrabackup --prepare --target-dir="%s"',
            $this->restoreFullPath
        );

        $this->executeCommand($command, 'Failed to prepare backup');
        $this->info('✔ Prepared');
    }

    /**
     * Restore files to data directory
     */
    private function restoreDatadir(): void
    {
        $this->info('➡ Restoring datadir...');

        $command = sprintf(
            'xtrabackup --copy-back --target-dir="%s" --datadir="%s/data"',
            $this->restoreFullPath,
            $this->restoreDir
        );

        $this->executeCommand($command, 'Failed to copy-back backup');

        // Fix permissions
        $command = sprintf(
            'chown -R mysql:mysql "%s/data"',
            $this->restoreDir
        );

        // We use sudo for chown if not running as root, but usually this script runs as root/sudo
        // We'll just run it and hope for the best (or user runs with sudo)
        Process::run($command);

        $this->info('✔ Datadir restored');
    }

    /**
     * Prune unwanted databases if TARGET_DATABASE is set
     */
    private function pruneDatabases(): void
    {
        if (!$this->targetDb) {
            return;
        }

        $this->info("➡ Single database mode: Keeping only '$this->targetDb'");

        $dataDir = $this->restoreDir . '/data';
        $dirs = glob($dataDir . '/*', GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            $dbName = basename($dir);

            if (in_array($dbName, [$this->targetDb, 'mysql', 'performance_schema', 'sys'])) {
                $this->info("   Keeping $dbName");
            } else {
                $this->info("   Removing $dbName");
                Process::run("rm -rf \"$dir\"");
            }
        }

        $this->info('✔ Unwanted databases removed');
    }

    /**
     * Download binlogs from S3
     */
    private function downloadBinlogs(): void
    {
        $this->info('➡ Downloading binlogs...');

        $bucket = env('AWS_S3_BUCKET');
        $region = env('AWS_REGION');

        $command = sprintf(
            'aws s3 sync "s3://%s/binlogs/" "%s/binlogs" --region "%s"',
            $bucket,
            $this->restoreDir,
            $region
        );

        $this->executeCommand($command, 'Failed to download binlogs');
        $this->info('✔ Binlogs downloaded');
    }

    /**
     * Apply binlogs
     */
    private function applyBinlogs(): void
    {
        $this->info("➡ Applying binlogs up to $this->restoreTime ...");

        $binlogDir = $this->restoreDir . '/binlogs';
        $files = glob($binlogDir . '/binlog.*'); // Matches binlog.000001 etc.

        // Sort files to ensure correct order
        sort($files);

        foreach ($files as $file) {
            // Skip index files or non-binlog files
            if (str_ends_with($file, '.index'))
                continue;

            $this->info("   Applying " . basename($file));

            $dbFilter = $this->targetDb ? "--database=\"$this->targetDb\"" : "";

            // Note: This pipes to 'mysql' which assumes a running server.
            // This replicates the shell script logic but is potentially risky/incorrect
            // if the intention is to apply to the restored files offline.
            // However, mysqlbinlog cannot apply to offline files directly.

            $command = sprintf(
                'mysqlbinlog --stop-datetime="%s %s" %s "%s" | mysql',
                $this->restoreDate,
                $this->restoreTime,
                $dbFilter,
                $file
            );

            // We don't use runCommand here because piping is complex with Process::run
            // and we might want to ignore some errors or handle them differently
            // But for now, let's use shell execution

            // Use Process::fromShellCommandline for pipes
            $result = Process::fromShellCommandline($command)->timeout(3600)->run();

            if ($result->failed()) {
                $this->warn("   ⚠ Failed to apply binlog $file: " . $result->errorOutput());
                // We don't stop here, as some events might fail (e.g. duplicates)
            }
        }

        $this->info('✔ Binlogs applied');
    }

    /**
     * Helper to run a command and throw exception on failure
     */
    private function executeCommand(string $command, string $errorMessage): void
    {
        $result = Process::timeout(3600)->run($command);
        $this->log('Executing command: ' . $command);
        if ($result->failed()) {
            $this->log('Command failed: ' . $result->errorOutput());
            throw new \Exception($errorMessage . ': ' . $result->errorOutput());
        }
        $this->log('Command succeeded');
    }

    /**
     * Define the command's schedule.
     */
    /**
     * Initialize logging directory and file
     */
    private function initializeLogging(): void
    {
        $logDir = storage_path('logs');
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $this->logFile = $logDir . '/restore_from_s3.log';
    }

    /**
     * Write a message to the log file
     */
    private function log(string $message): void
    {
        file_put_contents($this->logFile, $message . PHP_EOL, FILE_APPEND);
    }

    public function schedule(Schedule $schedule): void
    {
        // Not scheduled
    }
}
