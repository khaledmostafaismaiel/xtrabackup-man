#!/bin/bash
set -e

source /opt/xtrabackup-man/load_env.sh

LOG_DIR="/opt/xtrabackup-man/logs"
mkdir -p "$LOG_DIR"
LOG_FILE="$LOG_DIR/archive_binlogs.log"

echo "[$(date)] Starting binlog archive..." | tee -a "$LOG_FILE"

# Ensure binlog backup directory exists
mkdir -p "$BINLOG_BACKUP_DIR"

# Sync new binlogs locally
rsync -av --include='mysql-bin.*' --exclude='*' "$MYSQL_DIR/" "$BINLOG_BACKUP_DIR/" | tee -a "$LOG_FILE"

# Upload binlogs to S3
aws s3 sync "$BINLOG_BACKUP_DIR/" "s3://$AWS_S3_BUCKET/binlogs/" --region "$AWS_REGION" | tee -a "$LOG_FILE"

# Rotate logs older than 1 day
find "$LOG_DIR" -type f -name "*.log" -mtime +1 -exec gzip -f {} \;
find "$LOG_DIR" -type f -name "*.log.gz" -mtime +"$RETENTION_DAYS" -delete
touch "$LOG_FILE"

echo "[$(date)] Binlog archive completed" | tee -a "$LOG_FILE"
