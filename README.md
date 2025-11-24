# MySQL Backup & Restore Manager

![MySQL](https://img.shields.io/badge/MySQL-8.x-blue?logo=mysql)
![Percona XtraBackup](https://img.shields.io/badge/XtraBackup-8.x-orange)
![AWS S3](https://img.shields.io/badge/AWS-S3-yellow?logo=amazon-aws)

A **robust, enterprise-grade backup and restore solution** for large-scale MySQL databases with point-in-time recovery capabilities.

## ✨ Features

- 🔄 **Automated Full Backups** - Daily full backups using Percona XtraBackup
- 📝 **Binary Log Archiving** - Continuous binlog archiving every 30 minutes for PITR
- ☁️ **S3 Integration** - Automatic upload to AWS S3 for offsite storage
- ⏱️ **Point-in-Time Recovery** - Restore to any specific timestamp
- 🧹 **Automatic Cleanup** - Configurable retention policies for local and S3 backups
- 🔒 **Secure Configuration** - Environment-based configuration with no hardcoded credentials
- 📊 **Comprehensive Logging** - Detailed logging for monitoring and troubleshooting

---

## 📋 Table of Contents

- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Installation](#installation)
- [Configuration](#configuration)
- [Project Structure](#project-structure)
- [Scripts Reference](#scripts-reference)
- [Scheduling with Cron](#scheduling-with-cron)
- [Restore Procedures](#restore-procedures)
- [Security & Permissions](#security--permissions)
- [Logging](#logging)
- [Best Practices](#best-practices)
- [Troubleshooting](#troubleshooting)

---

## 🔧 Requirements

- **MySQL/MariaDB** 8.x (InnoDB storage engine recommended)
- **Operating System:** Linux (Ubuntu/Debian)
- **Percona XtraBackup** 8.x
- **AWS CLI** configured with S3 access
- **Bash** shell >= 4.x
- **Disk Space:** Sufficient space for backups and binary logs

---

## 🚀 Quick Start

```bash
# 1. Clone the repository
git clone https://github.com/khaledmostafaismaiel/xtrabackup-man.git
cd xtrabackup-man

# 2. Configure environment
cp .env.example .env
nano .env  # Edit with your settings

# 3. Set permissions
chmod 700 /opt/xtrabackup-man/*.sh
chmod 600 /opt/xtrabackup-man/.env
chmod 700 /opt/xtrabackup-man/logs
chown -R mysql:mysql /backups   # if necessary for MySQL

# 4. Setup cron jobs
crontab -e  # Add jobs from crontab.example
```
## 📦 Installation

### 1. AWS CLI Installation

#### Ubuntu/Debian

```bash
sudo apt update
sudo apt install -y awscli
aws --version
```

Configure AWS credentials:

```bash
aws configure
# Enter your AWS Access Key ID
# Enter your AWS Secret Access Key
# Default region: us-east-1 (or your preferred region)
# Default output format: json
```

### 2. Percona XtraBackup Installation

#### Ubuntu/Debian

```bash
wget https://repo.percona.com/apt/percona-release_latest.$(lsb_release -sc)_all.deb
sudo dpkg -i percona-release_latest.$(lsb_release -sc)_all.deb
sudo apt update
sudo apt install -y percona-xtrabackup-80
xtrabackup --version
```

---

## ⚙️ Configuration

Copy the example environment file and configure it:

```bash
cp .env.example .env
```

Edit `.env` with your settings:

> **Note:** Ensure the MySQL user has appropriate privileges (see [Security & Permissions](#security--permissions))

---

## 📁 Project Structure

```
xtrabackup-man/
├── full_backup.sh           # Daily full backup script
├── archive_binlogs.sh       # Binary log archiving script
├── cleanup.sh               # Cleanup old backups
├── restore_from_s3.sh       # Point-in-time restore script
├── load_env.sh              # Environment loader helper
├── .env                     # Configuration file (create from .env.example)
├── .env.example             # Example configuration
├── crontab.example          # Example cron schedule
├── logs/                    # Log directory
└── README.md                # This file
```

---

## 📜 Scripts Reference

### `full_backup.sh`

Performs a complete backup of the MySQL database using XtraBackup.

**Features:**
- Creates a full backup using `xtrabackup --backup`
- Prepares the backup for restore with `xtrabackup --prepare`
- Uploads to S3 with date-based naming
- Logs all operations

**Usage:**

```bash
./full_backup.sh
```

**Output:**
- Local backup: `$BACKUP_DIR/YYYY-MM-DD/`
- S3 location: `s3://$S3_BUCKET/$S3_PREFIX/full/YYYY-MM-DD.tar.gz`

---

### `archive_binlogs.sh`

Archives MySQL binary logs for point-in-time recovery.

**Features:**
- Syncs binary logs to local backup directory
- Uploads to S3 for offsite storage
- Non-intrusive (does not flush logs)
- Runs every 30 minutes via cron

**Usage:**

```bash
./archive_binlogs.sh
```

**Output:**
- Local: `$BINLOG_BACKUP_DIR/`
- S3 location: `s3://$S3_BUCKET/$S3_PREFIX/binlogs/`

---

### `cleanup.sh`

Removes old backups based on retention policy.

**Features:**
- Deletes local backups older than `RETENTION_DAYS`
- Removes corresponding S3 backups
- Cleans both full backups and binary logs
- Logs deletion operations

**Usage:**

```bash
./cleanup.sh
```

---

### `restore_from_s3.sh`

Restores database to a specific point in time.

**Features:**
- Downloads full backup from S3
- Applies binary logs up to specified timestamp
- Restores to custom directory
- Supports point-in-time recovery (PITR)

**Usage:**

```bash
./restore_from_s3.sh --date YYYY-MM-DD --time HH:MM:SS --restore-dir /path/to/restore
```

**Example:**

```bash
./restore_from_s3.sh --date 2025-11-24 --time 14:30:00 --restore-dir /restore/pitr-test
```

**Parameters:**
- `--date`: Date of the full backup to restore (YYYY-MM-DD)
- `--time`: Target restore timestamp (HH:MM:SS)
- `--restore-dir`: Directory where data will be restored

---

## ⏰ Scheduling with Cron

Edit your crontab:

```bash
crontab -e
```

Add the following entries (adjust paths as needed):

```cron
# Full backup daily at 2 AM
0 2 * * * /opt/xtrabackup-man/full_backup.sh >> /opt/xtrabackup-man/logs/full_backup.log 2>&1

# Binlog archive every 30 minutes
*/30 * * * * /opt/xtrabackup-man/archive_binlogs.sh >> /opt/xtrabackup-man/logs/archive_binlogs.log 2>&1

# Cleanup daily at 3 AM
0 3 * * * /opt/xtrabackup-man/cleanup.sh >> /opt/xtrabackup-man/logs/cleanup.log 2>&1
```

## 🔄 Restore Procedures

### Full Restore Process

#### Step 1: Run the Restore Script

```bash
./restore_from_s3.sh \
  --date 2025-11-24 \
  --time 14:30:00 \
  --restore-dir /restore/test-restore
```

#### Step 2: Start a Test MySQL Instance

```bash
mysqld \
  --datadir=/restore/test-restore/data \
  --port=3307 \
  --socket=/tmp/mysql-restore.sock \
  --skip-networking
```

#### Step 3: Connect and Verify

```bash
mysql -u root -S /tmp/mysql-restore.sock
```

Verify the data:

```sql
SHOW DATABASES;
USE your_database;
SELECT COUNT(*) FROM important_table;
-- Verify data matches expected state at restore time
```

#### Step 4: Production Restore (if verified)

If the test restore looks correct:

1. Stop production MySQL
2. Backup current data directory
3. Copy restored data to production location
4. Start MySQL
5. Verify application connectivity

---

## 🔒 Security & Permissions

### File Permissions

```bash
# Secure the backup directory
chmod 700 /opt/db-backups
chmod 600 /opt/db-backups/.env

# Make scripts executable
chmod 700 /opt/db-backups/*.sh

# Set ownership
chown -R root:root /opt/db-backups
```

### MySQL User Privileges

Create a dedicated backup user with minimal required privileges:

```sql
CREATE USER 'backup_user'@'localhost' IDENTIFIED BY 'secure_password';

GRANT RELOAD, LOCK TABLES, REPLICATION CLIENT, PROCESS 
ON *.* TO 'backup_user'@'localhost';

GRANT SELECT ON mysql.* TO 'backup_user'@'localhost';

FLUSH PRIVILEGES;
```

### AWS IAM Policy

Ensure your AWS credentials have the following S3 permissions:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "s3:PutObject",
        "s3:GetObject",
        "s3:ListBucket",
        "s3:DeleteObject"
      ],
      "Resource": [
        "arn:aws:s3:::your-bucket-name/*",
        "arn:aws:s3:::your-bucket-name"
      ]
    }
  ]
}
```

---

## 📊 Logging

All scripts log to `$LOG_DIR` (default: `/var/log/mysql-backup/`):

- `full_backup.log` - Full backup operations
- `archive_binlogs.log` - Binary log archiving
- `cleanup.log` - Cleanup operations

### Log Rotation

Configure logrotate for automatic log management:

```bash
sudo nano /etc/logrotate.d/mysql-backup
```

Add:

```
/var/log/mysql-backup/*.log {
    daily
    rotate 30
    compress
    delaycompress
    notifempty
    create 0640 root root
    sharedscripts
}
```

---

## ✅ Best Practices

### Backup Strategy

- ✅ Keep at least 2 full backups locally
- ✅ Store backups in multiple geographic locations (S3 + another cloud/region)
- ✅ Test restore procedures weekly on staging servers
- ✅ Document recovery time objectives (RTO) and recovery point objectives (RPO)

### Monitoring

- ✅ Monitor log files daily for failures
- ✅ Set up alerts for backup failures (email, Slack, PagerDuty)
- ✅ Verify S3 uploads complete successfully
- ✅ Check disk space regularly

### Configuration

- ✅ Set `RETENTION_DAYS` according to compliance requirements
- ✅ Enable MySQL binary logging: `log-bin=mysql-bin` in `my.cnf`
- ✅ Use GTID-based replication for safer point-in-time recovery
- ✅ Keep binlog format as `ROW` for complete data capture

### Security

- ✅ Never commit `.env` to version control
- ✅ Rotate backup user passwords periodically
- ✅ Use AWS IAM roles instead of access keys when possible
- ✅ Encrypt backups at rest (S3 encryption)
- ✅ Implement least-privilege access

---

## 🔍 Troubleshooting

### Common Issues

#### Backup Fails with Permission Denied

```bash
# Check file permissions
ls -la /opt/db-backups/
chmod 700 /opt/db-backups/*.sh
```

#### S3 Upload Fails

```bash
# Test AWS credentials
aws s3 ls s3://your-bucket-name/

# Check AWS CLI configuration
aws configure list
```

#### XtraBackup Not Found

```bash
# Verify installation
which xtrabackup
xtrabackup --version

# Add to PATH if needed
export PATH=$PATH:/usr/bin
```

#### Insufficient Disk Space

```bash
# Check disk usage
df -h /backups

# Clean old backups manually
./cleanup.sh

# Reduce RETENTION_DAYS in .env
```

#### Binary Log Replay Fails

```bash
# Verify binlog files exist
ls -la $BINLOG_BACKUP_DIR/

# Check binlog format
mysqlbinlog /path/to/binlog.000001 | head -20

# Ensure GTID consistency
mysql> SELECT @@GLOBAL.GTID_MODE;
```

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

**Khaled Mostafa.**