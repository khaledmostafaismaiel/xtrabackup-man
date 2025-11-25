#!/bin/bash
set -e

source ~/xtrabackup-man/load_env.sh

LOG_DIR="~/xtrabackup-man/logs"
mkdir -p "$LOG_DIR"
LOG_FILE="$LOG_DIR/cleanup.log"

echo "[$(date)] Starting cleanup..." | tee -a "$LOG_FILE"

# Delete old full backups
echo "[$(date)] Deleting full backups older than $RETENTION_DAYS days..." | tee -a "$LOG_FILE"
find "$FULL_BACKUP_DIR" -type d -mtime +"$RETENTION_DAYS" -exec rm -rf {} \;

# Delete old binlogs
echo "[$(date)] Deleting binlogs older than $RETENTION_DAYS days..." | tee -a "$LOG_FILE"
find "$BINLOG_BACKUP_DIR" -type f -mtime +"$RETENTION_DAYS" -delete

# Delete old full backups from S3
echo "[$(date)] Deleting old S3 full backups older than $RETENTION_DAYS days..." | tee -a "$LOG_FILE"
aws s3 rm "s3://$AWS_S3_BUCKET/full/" --recursive --exclude "*" --include "*$(date -d "$RETENTION_DAYS days ago" +%F)*" --region "$AWS_REGION" | tee -a "$LOG_FILE"

# Rotate logs
echo "[$(date)] Rotating logs..." | tee -a "$LOG_FILE"
find "$LOG_DIR" -type f -name "*.log" -mtime +1 -exec gzip -f {} \;
find "$LOG_DIR" -type f -name "*.log.gz" -mtime +"$RETENTION_DAYS" -delete
touch "$LOG_FILE"

echo "[$(date)] Cleanup completed" | tee -a "$LOG_FILE"
