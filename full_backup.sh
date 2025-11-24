#!/bin/bash
set -e

source /opt/xtrabackup-man/load_env.sh

LOG_DIR="/opt/xtrabackup-man/logs"
mkdir -p "$LOG_DIR"
LOG_FILE="$LOG_DIR/full_backup.log"

echo "[$(date)] Starting full backup..." | tee -a "$LOG_FILE"

DATE=$(date +%F)
DEST="$FULL_BACKUP_DIR/$DATE"

# Flush logs to start new binlog file
mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" -h "$MYSQL_HOST" -e "FLUSH LOGS;"

# Create backup
xtrabackup --backup \
    --target-dir="$DEST" \
    --user="$MYSQL_USER" \
    --password="$MYSQL_PASS" \
    --host="$MYSQL_HOST" \
    --parallel=4 \
    --compress \
    --compress-threads=4 | tee -a "$LOG_FILE"

# Prepare backup
xtrabackup --prepare --target-dir="$DEST" | tee -a "$LOG_FILE"

# Upload to S3
aws s3 sync "$DEST" "s3://$AWS_S3_BUCKET/full/$DATE/" --region "$AWS_REGION" | tee -a "$LOG_FILE"

# Rotate logs older than 1 day
find "$LOG_DIR" -type f -name "*.log" -mtime +1 -exec gzip -f {} \;

# Delete compressed logs older than RETENTION_DAYS
find "$LOG_DIR" -type f -name "*.log.gz" -mtime +"$RETENTION_DAYS" -delete

# Ensure log file exists for next run
touch "$LOG_FILE"

echo "[$(date)] Full backup completed" | tee -a "$LOG_FILE"
