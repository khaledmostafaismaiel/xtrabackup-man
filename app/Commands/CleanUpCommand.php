<?php

namespace App\Commands;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class CleanUpCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old backups and logs based on retention policy';

    private string $logFile;
    private int $retentionDaysLocal;
    private int $retentionDaysCloud;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            // Initialize logging and configuration
            $this->initializeConfig();

            // Display and log header
            $this->logHeader();

            // Step 1: Delete old local full backups
            $this->cleanLocalFullBackups();

            // Step 2: Delete old local binlogs
            $this->cleanLocalBinlogs();

            // Step 3: Delete old S3 full backups
            $this->cleanS3FullBackups();

            // Step 4: Delete old S3 binlogs
            $this->cleanS3Binlogs();

            // Step 5: Rotate logs
            $this->rotateLogs();

            // Display completion message
            $this->logFooter();

            $this->info('Cleanup completed successfully!');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Cleanup failed: ' . $e->getMessage());
            $this->log('ERROR: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Initialize logging and configuration
     */
    private function initializeConfig(): void
    {
        $logDir = storage_path('logs');

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $this->logFile = $logDir . '/cleanup.log';
        $this->retentionDaysLocal = (int) env('RETENTION_DAYS_FOR_LOCAL', 3);
        $this->retentionDaysCloud = (int) env('RETENTION_DAYS_FOR_CLOUD', 90);
    }

    /**
     * Display and log the header
     */
    private function logHeader(): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $this->info("[$timestamp] Starting cleanup...");
        $this->log("[$timestamp] Starting cleanup...");
        $this->info("Retention period (local): {$this->retentionDaysLocal} days");
        $this->log("Retention period (local): {$this->retentionDaysLocal} days");
        $this->info("Retention period (cloud): {$this->retentionDaysCloud} days");
        $this->log("Retention period (cloud): {$this->retentionDaysCloud} days");
    }

    /**
     * Display and log the footer
     */
    private function logFooter(): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $this->info("[$timestamp] Cleanup completed");
        $this->log("[$timestamp] Cleanup completed");
    }

    /**
     * Delete old local full backups
     */
    private function cleanLocalFullBackups(): void
    {
        $this->info("➡ Deleting local full backups older than {$this->retentionDaysLocal} days...");
        $this->log("➡ Deleting local full backups older than {$this->retentionDaysLocal} days...");

        $backupDir = storage_path('backups/full');

        if (!is_dir($backupDir)) {
            $this->warn("Backup directory not found: $backupDir");
            return;
        }

        // Find and delete directories older than retention days
        $command = sprintf(
            'find "%s" -type d -mtime +%d -exec rm -rf {} \\;',
            $backupDir,
            $this->retentionDaysLocal
        );

        $this->log('Command: ' . $command);
        Process::run($command);
    }

    /**
     * Delete old local binlogs
     */
    private function cleanLocalBinlogs(): void
    {
        $this->info("➡ Deleting local binlogs older than {$this->retentionDaysLocal} days...");
        $this->log("➡ Deleting local binlogs older than {$this->retentionDaysLocal} days...");

        $binlogDir = storage_path('backups/binlogs');

        if (!is_dir($binlogDir)) {
            $this->warn("Binlog directory not found: $binlogDir");
            return;
        }

        // Find and delete files older than retention days
        $command = sprintf(
            'find "%s" -type f -mtime +%d -delete',
            $binlogDir,
            $this->retentionDaysLocal
        );

        $this->log('Command: ' . $command);
        Process::run($command);
    }

    /**
     * Delete old S3 full backups
     * Note: This implements a more robust cleanup than the shell script
     * by listing objects and checking their dates.
     */
    private function cleanS3FullBackups(): void
    {
        $this->info("➡ Checking S3 full backups older than {$this->retentionDaysCloud} days...");
        $this->log("➡ Checking S3 full backups older than {$this->retentionDaysCloud} days...");

        $bucket = env('AWS_S3_BUCKET');
        $region = env('AWS_REGION');
        $prefix = 'full/';

        // List objects in the bucket
        $command = sprintf(
            'aws s3 ls "s3://%s/%s" --region "%s"',
            $bucket,
            $prefix,
            $region
        );

        $result = Process::run($command);

        if ($result->failed()) {
            $this->error('Failed to list S3 objects: ' . $result->errorOutput());
            $this->log('ERROR: Failed to list S3 objects: ' . $result->errorOutput());
            return;
        }

        $output = $result->output();
        $lines = explode("\n", $output);
        // Use startOfDay() to ensure we compare against calendar days
        $cutoffDate = Carbon::now()->subDays($this->retentionDaysCloud)->startOfDay();

        foreach ($lines as $line) {
            // Parse "PRE 2025-11-24/" or "2025-11-24 12:00:00 12345 filename"
            // Our structure is s3://bucket/full/YYYY-MM-DD/
            // So `aws s3 ls s3://bucket/full/` returns "PRE YYYY-MM-DD/"

            $line = trim($line);
            if (empty($line))
                continue;

            if (preg_match('/PRE\s+(\d{4}-\d{2}-\d{2})\//', $line, $matches)) {
                $dirDate = $matches[1];

                try {
                    $date = Carbon::parse($dirDate);

                    if ($date->lt($cutoffDate)) {
                        $this->deleteS3Directory($bucket, $prefix . $dirDate . '/', $region);
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }
    }

    /**
     * Delete old S3 binlogs
     */
    private function cleanS3Binlogs(): void
    {
        $this->info("➡ Checking S3 binlogs older than {$this->retentionDaysCloud} days...");
        $this->log("➡ Checking S3 binlogs older than {$this->retentionDaysCloud} days...");

        $bucket = env('AWS_S3_BUCKET');
        $region = env('AWS_REGION');
        $prefix = 'binlogs/';

        // List objects in the bucket
        $command = sprintf(
            'aws s3 ls "s3://%s/%s" --region "%s"',
            $bucket,
            $prefix,
            $region
        );

        $result = Process::run($command);

        if ($result->failed()) {
            $this->error('Failed to list S3 binlogs: ' . $result->errorOutput());
            $this->log('ERROR: Failed to list S3 binlogs: ' . $result->errorOutput());
            return;
        }

        $output = $result->output();
        $lines = explode("\n", $output);
        // Use startOfDay() to ensure we compare against calendar days
        $cutoffDate = Carbon::now()->subDays($this->retentionDaysCloud)->startOfDay();

        foreach ($lines as $line) {
            // Parse "2025-11-24 12:00:00 12345 filename"
            $line = trim($line);
            if (empty($line))
                continue;

            // Skip directory markers (PRE)
            if (str_starts_with($line, 'PRE'))
                continue;

            // Split by whitespace to get date and time
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 4)
                continue;

            $dateStr = $parts[0] . ' ' . $parts[1];
            $filename = end($parts);

            try {
                $date = Carbon::parse($dateStr);

                if ($date->lt($cutoffDate)) {
                    $this->deleteS3File($bucket, $prefix . $filename, $region);
                }
            } catch (\Exception $e) {
                continue;
            }
        }
    }

    /**
     * Delete a file in S3
     */
    private function deleteS3File(string $bucket, string $key, string $region): void
    {
        $this->info("Deleting old S3 binlog: $key");
        $this->log("Deleting old S3 binlog: $key");

        $command = sprintf(
            'aws s3 rm "s3://%s/%s" --region "%s"',
            $bucket,
            $key,
            $region
        );

        $this->log('Command: ' . $command);
        Process::run($command);
    }

    /**
     * Delete a directory in S3
     */
    private function deleteS3Directory(string $bucket, string $prefix, string $region): void
    {
        $this->info("Deleting old S3 backup: $prefix");
        $this->log("Deleting old S3 backup: $prefix");

        $command = sprintf(
            'aws s3 rm "s3://%s/%s" --recursive --region "%s"',
            $bucket,
            $prefix,
            $region
        );

        $this->log('Command: ' . $command);
        Process::run($command);
    }

    /**
     * Rotate old log files
     */
    private function rotateLogs(): void
    {
        $this->info("➡ Rotating logs...");
        $this->log("➡ Rotating logs...");

        $logDir = storage_path('logs');

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
            $this->retentionDaysLocal
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
        // Run cleanup daily
        $schedule->command(static::class)->dailyAt('03:00');
    }
}
