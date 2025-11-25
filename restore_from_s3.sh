#!/bin/bash
set -e

source /opt/xtrabackup-man/load_env.sh

#############################################
# Parse arguments
#############################################
usage() {
    echo "Usage: $0 --date <YYYY-MM-DD> --time <HH:MM:SS> --restore-dir </path>"
    exit 1
}

while [[ "$#" -gt 0 ]]; do
    case $1 in
        --date) RESTORE_DATE="$2"; shift ;;
        --time) RESTORE_TIME="$2"; shift ;;
        --restore-dir) RESTORE_DIR="$2"; shift ;;
        *) echo "Unknown parameter: $1"; usage ;;
    esac
    shift
done

if [[ -z "$RESTORE_DATE" || -z "$RESTORE_TIME" || -z "$RESTORE_DIR" ]]; then
    usage
fi

TARGET_DB="${TARGET_DATABASE:-}"

echo "==============================================="
echo "        RESTORE PROCESS STARTED"
echo "==============================================="
echo "Date        : $RESTORE_DATE"
echo "Time        : $RESTORE_TIME"
echo "Restore dir : $RESTORE_DIR"
echo "Target DB   : ${TARGET_DB:-ALL DATABASES}"
echo "==============================================="

if [[ -d "$RESTORE_DIR" ]]; then
    echo "❌ ERROR: Restore directory already exists."
    exit 1
fi

mkdir -p "$RESTORE_DIR/binlogs"
mkdir -p "$RESTORE_DIR/full"

#############################################
# Step 1: Download full backup
#############################################
echo "➡ Downloading full backup..."

aws s3 sync "s3://$AWS_S3_BUCKET/full/$RESTORE_DATE" \
    "$RESTORE_DIR/full" --region "$AWS_REGION"

echo "✔ Full backup downloaded"


#############################################
# Step 2: Prepare backup
#############################################
echo "➡ Preparing backup with xtrabackup..."
xtrabackup --prepare --target-dir="$RESTORE_DIR/full"
echo "✔ Prepared"


#############################################
# Step 3: Restore into datadir
#############################################
echo "➡ Restoring datadir..."

mkdir -p "$RESTORE_DIR/data"

xtrabackup --copy-back --target-dir="$RESTORE_DIR/full" \
    --datadir="$RESTORE_DIR/data"

chown -R mysql:mysql "$RESTORE_DIR/data"
echo "✔ Datadir restored"


#############################################
# Step 4: Optional pruning (single-database restore)
#############################################
if [[ -n "$TARGET_DB" ]]; then
    echo "➡ Single database mode: Keeping only '$TARGET_DB'"

    cd "$RESTORE_DIR/data"

    for db in */ ; do
        db="${db%/}"
        case "$db" in
            "$TARGET_DB"|"mysql"|"performance_schema"|"sys")
                echo "   Keeping $db"
                ;;
            *)
                echo "   Removing $db"
                rm -rf "$db"
                ;;
        esac
    done

    echo "✔ Unwanted databases removed"
fi


#############################################
# Step 5: Download binlogs
#############################################
echo "➡ Downloading binlogs..."

aws s3 sync "s3://$AWS_S3_BUCKET/binlogs/" \
    "$RESTORE_DIR/binlogs" --region "$AWS_REGION"

echo "✔ Binlogs downloaded"


#############################################
# Step 6: Apply binlogs
#############################################
echo "➡ Applying binlogs up to $RESTORE_TIME ..."

for binlog in $(ls "$RESTORE_DIR/binlogs" | sort); do
    file="$RESTORE_DIR/binlogs/$binlog"

    if [[ -n "$TARGET_DB" ]]; then
        mysqlbinlog --stop-datetime="$RESTORE_DATE $RESTORE_TIME" \
            --database="$TARGET_DB" "$file" | mysql
    else
        mysqlbinlog --stop-datetime="$RESTORE_DATE $RESTORE_TIME" \
            "$file" | mysql
    fi
done

echo "✔ Binlogs applied"


#############################################
# DONE
#############################################
echo ""
echo "==============================================="
echo "   RESTORE COMPLETED SUCCESSFULLY"
echo "   Data restored into: $RESTORE_DIR/data"
echo "==============================================="
