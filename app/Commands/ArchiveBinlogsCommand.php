<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class ArchiveBinlogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:binlogs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archive MySQL binary logs and upload to S3';

    private string $logFile;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            // Initialize logging
            $this->initializeLogging();

            // Display and log header
            $this->logHeader();

            // Prepare binlog backup directory
            $binlogDir = $this->prepareBinlogDirectory();

            // Step 1: Sync binlogs locally
            $this->syncBinlogs($binlogDir);

            // Step 2: Upload to S3
            $this->uploadToS3($binlogDir);

            // Step 3: Rotate old logs
            $this->rotateLogs();

            // Display completion message
            $this->logFooter();

            $this->info('Binlog archive completed successfully!');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Binlog archive failed: ' . $e->getMessage());
            $this->log('ERROR: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Initialize logging directory and file
     */
    private function initializeLogging(): void
    {
        $logDir = storage_path('logs');

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $this->logFile = $logDir . '/archive_binlogs.log';
    }

    /**
     * Display and log the header
     */
    private function logHeader(): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $this->info("[$timestamp] Starting binlog archive...");
        $this->log("[$timestamp] Starting binlog archive...");
    }

    /**
     * Display and log the footer
     */
    private function logFooter(): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $this->info("[$timestamp] Binlog archive completed");
        $this->log("[$timestamp] Binlog archive completed");
    }

    /**
     * Prepare the binlog backup directory
     */
    private function prepareBinlogDirectory(): string
    {
        // Use local backups directory in the project root
        $binlogDir = storage_path('backups/binlogs');

        // Create directory if it doesn't exist
        if (!is_dir($binlogDir)) {
            $created = @mkdir($binlogDir, 0755, true);

            if (!$created) {
                $error = error_get_last();
                throw new \Exception(
                    'Failed to create binlog directory: ' . $binlogDir .
                    ' - Error: ' . ($error['message'] ?? 'Unknown error')
                );
            }

            $this->log('Created binlog directory: ' . $binlogDir);
        }

        return $binlogDir;
    }

    /**
     * Sync binlogs locally using rsync
     */
    private function syncBinlogs(string $binlogDir): void
    {
        $this->info('➡ Syncing binlogs locally...');
        $this->log('➡ Syncing binlogs locally...');

        $mysqlDir = env('MYSQL_DIR', '/var/lib/mysql');

        $command = sprintf(
            'rsync -av --include="binlog.*" --exclude="*" "%s/" "%s/"',
            $mysqlDir,
            $binlogDir
        );

        $this->log('Command: ' . $command);

        $result = Process::timeout(600)->run($command);

        // Log output
        if ($result->output()) {
            $this->log('Output: ' . $result->output());
        }

        if ($result->errorOutput()) {
            $this->log('Error Output: ' . $result->errorOutput());
        }

        if ($result->failed()) {
            throw new \Exception('Failed to sync binlogs: ' . $result->errorOutput());
        }

        $this->info('✔ Binlogs synced successfully');
        $this->log('✔ Binlogs synced successfully');
    }

    /**
     * Upload binlogs to S3
     */
    private function uploadToS3(string $binlogDir): void
    {
        $this->info('➡ Uploading binlogs to S3...');
        $this->log('➡ Uploading binlogs to S3...');

        $bucket = env('AWS_S3_BUCKET');
        $region = env('AWS_REGION');

        $command = sprintf(
            'aws s3 sync "%s/" "s3://%s/binlogs/" --region "%s"',
            $binlogDir,
            $bucket,
            $region
        );

        $this->log('Command: ' . $command);

        $result = Process::timeout(3600)->run($command);

        // Log output
        if ($result->output()) {
            $this->log('Output: ' . $result->output());
        }

        if ($result->errorOutput()) {
            $this->log('Error Output: ' . $result->errorOutput());
        }

        if ($result->failed()) {
            throw new \Exception('Failed to upload binlogs to S3: ' . $result->errorOutput());
        }

        $this->info('✔ Binlogs uploaded to S3');
        $this->log('✔ Binlogs uploaded to S3');
    }

    /**
     * Rotate old log files
     */
    private function rotateLogs(): void
    {
        $logDir = storage_path('logs');
        $retentionDays = env('RETENTION_DAYS_FOR_LOCAL', 3);

        // Compress logs older than 1 day
        $compressCmd = sprintf(
            'find "%s" -type f -name "*.log" -mtime +1 -exec gzip -f {} \\;',
            $logDir
        );

        Process::run($compressCmd);

        // Delete compressed logs older than retention period
        $deleteCmd = sprintf(
            'find "%s" -type f -name "*.log.gz" -mtime +%d -delete',
            $logDir,
            $retentionDays
        );

        Process::run($deleteCmd);

        // Touch the log file to keep it active
        touch($this->logFile);
    }

    /**
     * Log message to file
     */
    private function log(string $message): void
    {
        file_put_contents($this->logFile, $message . PHP_EOL, FILE_APPEND);
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // Run binlog archive every 30 minutes
        $schedule->command(static::class)->everyThirtyMinutes();
    }
}
