#!/bin/bash
set -e

#############################################
# Load environment
#############################################
source ~/xtrabackup-man/load_env.sh

#############################################
# Start logging
#############################################
LOG_DIR="~/xtrabackup-man/logs"
mkdir -p "$LOG_DIR"
LOG_FILE="$LOG_DIR/backup_full.log"

echo "===============================================" | tee -a "$LOG_FILE"
echo "  FULL BACKUP STARTED" | tee -a "$LOG_FILE"
echo "===============================================" | tee -a "$LOG_FILE"
echo "Date: $(date)" | tee -a "$LOG_FILE"
echo "Target Database: ${DB_NAME:-ALL DATABASES}" | tee -a "$LOG_FILE"
echo "S3 Bucket: $AWS_S3_BUCKET" | tee -a "$LOG_FILE"
echo "===============================================" | tee -a "$LOG_FILE"

#############################################
# Prepare backup directory
#############################################
TODAY=$(date +%F)
BACKUP_DIR="$FULL_BACKUP_DIR/$TODAY"

mkdir -p "$BACKUP_DIR"

#############################################
# Step 1: Run XtraBackup
#############################################
echo "➡ Running XtraBackup..." | tee -a "$LOG_FILE"

if [[ -n "$DB_NAME" ]]; then
    # Backup only a single database by including/excluding tables
    # XtraBackup does not support single database natively, workaround: backup full and remove unwanted dirs later
    xtrabackup --backup --target-dir="$BACKUP_DIR" | tee -a "$LOG_FILE"
    echo "✔ Full instance backup done. Will prune unwanted databases during restore."
else
    # Full instance backup
    xtrabackup --backup --target-dir="$BACKUP_DIR" | tee -a "$LOG_FILE"
fi

#############################################
# Step 2: Flush logs (optional)
#############################################
echo "➡ Flushing MySQL binary logs..." | tee -a "$LOG_FILE"
mysql -u "$DB_USER" -p"$DB_PASSWORD" -h "$DB_HOST" -e "FLUSH LOGS;" | tee -a "$LOG_FILE"

#############################################
# Step 3: Upload to S3
#############################################
echo "➡ Uploading backup to S3..." | tee -a "$LOG_FILE"

aws s3 sync "$BACKUP_DIR" "s3://$AWS_S3_BUCKET/full/$TODAY" --region "$AWS_REGION" | tee -a "$LOG_FILE"

echo "✔ Backup uploaded to S3" | tee -a "$LOG_FILE"

#############################################
# Step 4: Finish
#############################################
echo "===============================================" | tee -a "$LOG_FILE"
echo "  FULL BACKUP COMPLETED" | tee -a "$LOG_FILE"
echo "===============================================" | tee -a "$LOG_FILE"
