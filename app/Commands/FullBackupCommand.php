<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class FullBackupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:full';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Perform a full MySQL backup using XtraBackup and upload to S3';

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

            // Prepare backup directory
            $backupDir = $this->prepareBackupDirectory();

            // Step 1: Run XtraBackup
            $this->runXtraBackup($backupDir);

            // Step 2: Flush MySQL logs
            $this->flushMySQLLogs();

            // Step 3: Upload to S3
            $this->uploadToS3($backupDir);

            // Display completion message
            $this->logFooter();

            $this->info('Full backup completed successfully!');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Backup failed: ' . $e->getMessage());
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

        $this->logFile = $logDir . '/full_backup.log';
    }

    /**
     * Display and log the header
     */
    private function logHeader(): void
    {
        $header = [
            '===============================================',
            '  FULL BACKUP STARTED',
            '===============================================',
            'Date: ' . date('Y-m-d H:i:s'),
            'Target Database: ' . (env('TARGET_DATABASE') ?: 'ALL DATABASES'),
            'S3 Bucket: ' . env('AWS_S3_BUCKET'),
            '===============================================',
        ];

        foreach ($header as $line) {
            $this->info($line);
            $this->log($line);
        }
    }

    /**
     * Display and log the footer
     */
    private function logFooter(): void
    {
        $footer = [
            '===============================================',
            '  FULL BACKUP COMPLETED',
            '===============================================',
        ];

        foreach ($footer as $line) {
            $this->info($line);
            $this->log($line);
        }
    }

    /**
     * Prepare the backup directory
     */
    private function prepareBackupDirectory(): string
    {
        $today = date('Y-m-d');

        // Use local backups directory in the storage path
        $backupRoot = storage_path('backups');

        // Build the full backup directory path
        $fullBackupDir = $backupRoot . '/full';
        $backupDir = $fullBackupDir . '/' . $today;

        // Log the actual path being used
        $this->log('Target backup directory: ' . $backupDir);

        // Remove existing backup directory if it exists
        // XtraBackup requires the target directory to be empty or non-existent
        if (is_dir($backupDir)) {
            $this->log('Removing existing incomplete backup: ' . $backupDir);
            $this->warn('⚠ Found existing backup directory, removing it...');

            // Use shell command to remove with proper permissions
            $result = Process::run("rm -rf \"$backupDir\"");

            if ($result->failed()) {
                throw new \Exception('Failed to remove existing backup directory: ' . $backupDir);
            }
        }

        // Create directory structure with proper error handling
        // Use @ to suppress warnings, we'll check for errors manually
        $created = @mkdir($backupDir, 0755, true);

        if (!$created) {
            $error = error_get_last();
            throw new \Exception(
                'Failed to create backup directory: ' . $backupDir .
                ' - Error: ' . ($error['message'] ?? 'Unknown error')
            );
        }

        $this->log('Created backup directory: ' . $backupDir);

        // Ensure proper permissions after creation
        chmod($backupDir, 0755);

        // Verify directory is writable
        if (!is_writable($backupDir)) {
            throw new \Exception('Backup directory is not writable: ' . $backupDir);
        }

        $this->log('Backup directory prepared: ' . $backupDir);

        return $backupDir;
    }

    /**
     * Run XtraBackup
     */
    private function runXtraBackup(string $backupDir): void
    {
        $this->info('➡ Running XtraBackup...');
        $this->log('➡ Running XtraBackup...');

        $dbName = env('TARGET_DATABASE');
        $user = env('MYSQL_USER');
        $password = env('MYSQL_PASS');
        $host = env('MYSQL_HOST', 'localhost');
        $port = env('MYSQL_PORT', '3306');
        $datadir = env('MYSQL_DIR', '/var/lib/mysql');

        // Build xtrabackup command with MySQL credentials
        $command = sprintf(
            'xtrabackup --backup --user="%s" --password="%s" --host="%s" --port="%s" --datadir="%s" --target-dir="%s"',
            $user,
            $password,
            $host,
            $port,
            $datadir,
            $backupDir
        );

        if (!empty($dbName)) {
            $this->log('Command: xtrabackup --backup --user="' . $user . '" --password="***" --host="' . $host . '" --port="' . $port . '" --datadir="' . $datadir . '" --target-dir="' . $backupDir . '"');
            $this->info('Note: Full instance backup will be done. Will prune unwanted databases during restore.');
        } else {
            $this->log('Command: xtrabackup --backup --user="' . $user . '" --password="***" --host="' . $host . '" --port="' . $port . '" --datadir="' . $datadir . '" --target-dir="' . $backupDir . '"');
        }

        // Note: If permission denied error occurs, the command may need to run with sudo
        // or the user needs to be added to the mysql group
        $this->log('Note: If permission denied, run with: sudo -E php application backup:full');

        $result = Process::timeout(3600)->run($command);

        // Log output and errors
        if ($result->output()) {
            $this->log('Output: ' . $result->output());
        }

        if ($result->errorOutput()) {
            $this->log('Error Output: ' . $result->errorOutput());
        }

        if ($result->failed()) {
            throw new \Exception('XtraBackup failed: ' . $result->errorOutput());
        }

        $this->info('✔ XtraBackup completed successfully');
        $this->log('✔ XtraBackup completed successfully');
    }

    /**
     * Flush MySQL binary logs
     */
    private function flushMySQLLogs(): void
    {
        $this->info('➡ Flushing MySQL binary logs...');
        $this->log('➡ Flushing MySQL binary logs...');

        $user = env('MYSQL_USER');
        $password = env('MYSQL_PASS');
        $host = env('MYSQL_HOST', 'localhost');

        $command = sprintf(
            'mysql -u "%s" -p"%s" -h "%s" -e "FLUSH LOGS;"',
            $user,
            $password,
            $host
        );

        $this->log('Command: mysql -u "' . $user . '" -p"***" -h "' . $host . '" -e "FLUSH LOGS;"');

        $result = Process::timeout(60)->run($command);

        // Log output and errors
        if ($result->output()) {
            $this->log('Output: ' . $result->output());
        }

        if ($result->errorOutput()) {
            $this->log('Error Output: ' . $result->errorOutput());
        }

        if ($result->failed()) {
            throw new \Exception('Failed to flush MySQL logs: ' . $result->errorOutput());
        }

        $this->info('✔ MySQL logs flushed successfully');
        $this->log('✔ MySQL logs flushed successfully');
    }

    /**
     * Upload backup to S3
     */
    private function uploadToS3(string $backupDir): void
    {
        $this->info('➡ Uploading backup to S3...');
        $this->log('➡ Uploading backup to S3...');

        $bucket = env('AWS_S3_BUCKET');
        $region = env('AWS_REGION');
        $today = date('Y-m-d');

        $command = sprintf(
            'aws s3 sync "%s" "s3://%s/full/%s" --region "%s"',
            $backupDir,
            $bucket,
            $today,
            $region
        );

        $this->log('Command: ' . $command);

        $result = Process::timeout(7200)->run($command);

        // Log output and errors
        if ($result->output()) {
            $this->log('Output: ' . $result->output());
        }

        if ($result->errorOutput()) {
            $this->log('Error Output: ' . $result->errorOutput());
        }

        if ($result->failed()) {
            throw new \Exception('Failed to upload to S3: ' . $result->errorOutput());
        }

        $this->info('✔ Backup uploaded to S3');
        $this->log('✔ Backup uploaded to S3');
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
        // Run full backup daily at 2 AM
        $schedule->command(static::class)->dailyAt('02:00');
    }
}
