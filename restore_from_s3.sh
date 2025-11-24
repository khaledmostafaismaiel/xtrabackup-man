#!/bin/bash
set -e

#############################################
# Load environment
#############################################
source /opt/xtrabackup-man/load_env.sh

LOG_DIR="/opt/xtrabackup-man/logs"
mkdir -p "$LOG_DIR"
LOG_FILE="$LOG_DIR/restore.log"

#############################################
# Parse arguments
#############################################
usage() {
    echo "Usage: $0 --date <YYYY-MM-DD> --time <HH:MM:SS> --restore-dir </path/to/restore>"
    exit 1
}

while [[ "$#" -gt 0 ]]; do
    case $1 in
        --date) RESTORE_DATE="$2"; shift ;;
        --time) RESTORE_TIME="$2"; shift ;;
        --restore-dir) RESTORE_DIR="$2"; shift ;;
        *) echo "Unknown parameter passed: $1"; usage ;;
    esac
    shift
done

if [[ -z "$RESTORE_DATE" || -z "$RESTORE_TIME" || -z "$RESTORE_DIR" ]]; then
    usage
fi

#############################################
# Start logging
#############################################
echo "===============================================" | tee -a "$LOG_FILE"
echo "  RESTORE STARTED" | tee -a "$LOG_FILE"
echo "===============================================" | tee -a "$LOG_FILE"
echo "Restore date: $RESTORE_DATE" | tee -a "$LOG_FILE"
echo "Restore time: $RESTORE_TIME" | tee -a "$LOG_FILE"
echo "Local restore directory: $RESTORE_DIR" | tee -a "$LOG_FILE"
echo "S3 bucket: $AWS_S3_BUCKET" | tee -a "$LOG_FILE"
echo "" | tee -a "$LOG_FILE"

#############################################
# Sanity checks
#############################################
if [[ -d "$RESTORE_DIR" ]]; then
    echo "❌ ERROR: Restore directory already exists. Choose a new one." | tee -a "$LOG_FILE"
    exit 1
fi

mkdir -p "$RESTORE_DIR/binlogs"
mkdir -p "$RESTORE_DIR/full"
mkdir -p "$RESTORE_DIR/data"

#############################################
# Step 1: Download full backup from S3
#############################################
echo "➡ Downloading full backup from S3..." | tee -a "$LOG_FILE"

S3_FULL_PATH="s3://$AWS_S3_BUCKET/full/$RESTORE_DATE"
aws s3 sync "$S3_FULL_PATH" "$RESTORE_DIR/full" --region "$AWS_REGION" | tee -a "$LOG_FILE"

if [[ ! -d "$RESTORE_DIR/full" ]]; then
    echo "❌ ERROR: No full backup found at $S3_FULL_PATH" | tee -a "$LOG_FILE"
    exit 1
fi

echo "✔ Full backup downloaded" | tee -a "$LOG_FILE"

#############################################
# Step 2: Prepare the XtraBackup full backup
#############################################
echo "➡ Preparing full backup with xtrabackup..." | tee -a "$LOG_FILE"
xtrabackup --prepare --target-dir="$RESTORE_DIR/full" | tee -a "$LOG_FILE"
echo "✔ Full backup prepared" | tee -a "$LOG_FILE"

#############################################
# Step 3: Restore full backup to restore directory
#############################################
echo "➡ Restoring MySQL data directory to $RESTORE_DIR/data" | tee -a "$LOG_FILE"
xtrabackup --copy-back --target-dir="$RESTORE_DIR/full" --datadir="$RESTORE_DIR/data" | tee -a "$LOG_FILE"
echo "✔ Full backup restored" | tee -a "$LOG_FILE"

#############################################
# Step 4: Fix file permissions
#############################################
echo "➡ Fixing data directory permissions..." | tee -a "$LOG_FILE"
chown -R mysql:mysql "$RESTORE_DIR/data"
echo "✔ Permissions fixed" | tee -a "$LOG_FILE"

#############################################
# Step 5: Download binlogs from S3
#############################################
echo "➡ Downloading binlogs from S3..." | tee -a "$LOG_FILE"
aws s3 sync "s3://$AWS_S3_BUCKET/binlogs/" "$RESTORE_DIR/binlogs" --region "$AWS_REGION" | tee -a "$LOG_FILE"
echo "✔ Binlogs downloaded" | tee -a "$LOG_FILE"

#############################################
# Step 6: Replay binlogs up to the given timestamp
#############################################
echo "➡ Applying binlogs up to $RESTORE_DATE $RESTORE_TIME ..." | tee -a "$LOG_FILE"

for binlog in $(ls "$RESTORE_DIR/binlogs" | sort); do
    if [[ $binlog == mysql-bin.* ]]; then
        FILE_PATH="$RESTORE_DIR/binlogs/$binlog"
        echo "  - Applying $binlog" | tee -a "$LOG_FILE"
        mysqlbinlog --stop-datetime="$RESTORE_DATE $RESTORE_TIME" "$FILE_PATH" | \
            mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" -h "$MYSQL_HOST" | tee -a "$LOG_FILE"
    fi
done

echo "✔ Binlogs applied successfully" | tee -a "$LOG_FILE"

#############################################
# Step 7: Rotate restore logs
#############################################
# Compress logs older than 1 day
find "$LOG_DIR" -type f -name "*.log" -mtime +1 -exec gzip -f {} \;
# Delete compressed logs older than RETENTION_DAYS
find "$LOG_DIR" -type f -name "*.log.gz" -mtime +"$RETENTION_DAYS" -delete
# Ensure current log exists
touch "$LOG_FILE"

#############################################
# Done
#############################################
echo "===============================================" | tee -a "$LOG_FILE"
echo "  RESTORE COMPLETED SUCCESSFULLY" | tee -a "$LOG_FILE"
echo "===============================================" | tee -a "$LOG_FILE"
echo "Restored DB path: $RESTORE_DIR/data" | tee -a "$LOG_FILE"
echo "To start MySQL with this data directory:" | tee -a "$LOG_FILE"
echo "mysqld --datadir=$RESTORE_DIR/data --port=3307 --socket=/tmp/mysql-restore.sock" | tee -a "$LOG_FILE"

#############################################
# How To Use
#############################################
# /opt/xtrabackup-man/restore_from_s3.sh \
#   --date 2025-01-14 \
#   --time 15:30:00 \
#   --restore-dir /restore/test-restore
