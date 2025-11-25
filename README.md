# MySQL Backup & Restore Manager

![MySQL](https://img.shields.io/badge/MySQL-8.x-blue?logo=mysql)
![Percona XtraBackup](https://img.shields.io/badge/XtraBackup-8.x-orange)
![AWS S3](https://img.shields.io/badge/AWS-S3-yellow?logo=amazon-aws)

**Originally built with a collection of Bash shell scripts, this project has been refactored into a Laravel Zero application**, providing the same robust, enterprise‑grade backup and restore capabilities with a more maintainable, extensible codebase.

## ✨ Features

- 🔄 Automated Full Backups – Daily full backups using Percona XtraBackup
- 📝 Binary Log Archiving – Continuous binlog archiving every 30 minutes for PITR
- ☁️ S3 Integration – Automatic upload to AWS S3 for off‑site storage
- ⏱️ Point‑in‑Time Recovery – Restore to any specific timestamp
- 🧹 Automatic Cleanup – Configurable retention policies for local and S3 backups
- 🔒 Secure Configuration – Environment‑based configuration with no hard‑coded credentials
- 📊 Comprehensive Logging – Detailed logging for monitoring and troubleshooting

---

## 📋 Table of Contents

- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Installation](#installation)
- [Configuration](#configuration)
- [Project Structure](#project-structure)
- [Usage (Laravel Commands)](#usage-laravel-commands)
- [Scheduling with Cron](#scheduling-with-cron)
- [Security & Permissions](#security--permissions)
- [Logging](#logging)
- [Best Practices](#best-practices)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)

---

## 🔧 Requirements

- **MySQL/MariaDB** 8.x (InnoDB storage engine recommended)
- **Linux** (Ubuntu/Debian) – other distros may work with minor adjustments
- **Percona XtraBackup** 8.x
- **AWS CLI** configured with S3 access
- **PHP 8.2+** and **Composer**

---

## 🚀 Quick Start

```bash
# Clone the repository
git clone https://github.com/khaledmostafaismaiel/xtrabackup-man.git
cd xtrabackup-man

# Install PHP dependencies (Laravel Zero)
composer install --no-dev --optimize-autoloader

# Copy environment file and edit settings
cp .env.example .env
nano .env   # set DB credentials, S3 bucket, retention, etc.

# Ensure storage directories exist and are writable
chmod -R 700 storage/logs
chmod -R 700 storage/backups
```

---

## 📦 Installation

### 1. AWS CLI Installation

```bash
sudo apt update
sudo apt install -y awscli
aws --version
```

Configure credentials:

```bash
aws configure
```

### 2. Percona XtraBackup Installation

```bash
wget https://repo.percona.com/apt/percona-release_latest.$(lsb_release -sc)_all.deb
sudo dpkg -i percona-release_latest.$(lsb_release -sc)_all.deb
sudo percona-release enable-only tools release
sudo apt update
sudo apt install -y percona-xtrabackup-80
xtrabackup --version
```

---

## ⚙️ Configuration

Copy the example environment file and adjust values:

```bash
cp .env.example .env
nano .env
```

---

## 📁 Project Structure

```text
xtrabackup-man/
├── app/
│   └── Commands/                # Laravel Zero commands
│       ├── FullBackupCommand.php
│       ├── BinlogArchiveCommand.php
│       ├── CleanupCommand.php
│       └── RestoreCommand.php
├── config/                       # Configuration files (if any)
├── storage/
│   ├── logs/                     # Application logs
│   └── backups/                  # Local backup storage (git‑ignored)
│       ├── full/                # Full backups by date
│       └── binlogs/             # Binary log archives
├── .env.example
├── composer.json
├── README.md
└── vendor/                      # Composer dependencies
```

---

## 📜 Usage (Laravel Commands)

All backup operations are now Laravel Zero commands executed via `php artisan` (or the provided binary `application`). Use `sudo -E` when the MySQL user or S3 credentials require elevated permissions.

| Command | Description |
|---------|-------------|
| `php application backup:full` | Perform a full XtraBackup, prepare it, and upload to S3. |
| `php application backup:binlogs` | Archive current binary logs and upload to S3. |
| `php application backup:cleanup` | Remove old local and S3 backups according to `RETENTION_DAYS_FOR_LOCAL` and `RETENTION_DAYS_FOR_CLOUD`. |
| `php application backup:restore --date=YYYY-MM-DD --time=HH:MM:SS` | Restore a specific backup and apply binary logs up to the given timestamp. 

### Command Details

- **`backup:full`** – Performs a complete XtraBackup of the configured MySQL instance, prepares the backup for restore, and uploads the resulting archive to the configured S3 bucket. Logs are written to `storage/logs/full_backup.log`.
- **`backup:binlogs`** – Archives the current MySQL binary logs, uploads them to S3, and records the operation in `storage/logs/archive_binlogs.log`. This enables point‑in‑time recovery.
- **`backup:cleanup`** – Deletes local backups older than `RETENTION_DAYS_FOR_LOCAL` and S3 backups older than `RETENTION_DAYS_FOR_CLOUD` as defined in `.env`. This allows different retention policies for local versus cloud storage and is logged to `storage/logs/cleanup.log`.
- **`backup:restore`** – Downloads the specified full backup from S3, applies binary logs up to the provided timestamp, and restores the data to a target directory (optional `--restore-dir`).

| Logs for each command are written to `storage/logs/<command>.log`.

---

## ⏰ Scheduling with Cron

Add the following entries to your crontab (replace `/home/khaled` with the actual path):

```cron
* * * * * /usr/bin/php /home/khaled/xtrabackup-man/application schedule:run >> /home/khaled/xtrabackup-man/storage/logs/cron.log 2>&1
```

---

---

## 🔒 Security & Permissions

```bash
# Secure the backup directory
chmod -R 700 storage/backups
chmod 600 .env
```

---

## 📊 Logging

All commands write to `storage/logs/`. Rotate logs with `logrotate`:

```bash
sudo nano /etc/logrotate.d/mysql-backup
```
Add:

```text
/storage/logs/*.log {
    daily
    rotate 30
    compress
    missingok
    notifempty
    create 0640 root root
    sharedscripts
}
```

---

## ✅ Best Practices

- Keep at least two recent full backups locally.
- Store backups in multiple geographic locations (S3 + another cloud/region).
- Test restore procedures weekly on a staging server.
- Monitor logs and set up alerts for failures (email, Slack, etc.).
- Regularly rotate backup user passwords and use IAM roles when possible.
- Enable MySQL binary logging (`log-bin=mysql-bin`) and use GTID‑based replication for safer PITR.

---

## 🔍 Troubleshooting

### Common Issues

- **Permission denied** – Verify directory permissions (`chmod 700 storage/*`).
- **S3 upload fails** – Run `aws s3 ls s3://$S3_BUCKET` to test credentials.
- **XtraBackup not found** – Ensure `xtrabackup` is in `$PATH` (`which xtrabackup`).
- **Insufficient disk space** – Check with `df -h` and adjust `RETENTION_DAYS_FOR_LOCAL` to reduce local backup retention.
- **Binary log replay fails** – Confirm binlog files exist in `storage/backups/binlogs/` and GTID consistency.

---

## 🤝 Contributing

Contributions are welcome! Please submit a Pull Request with a clear description of changes.

---

**Khaled Mostafa**