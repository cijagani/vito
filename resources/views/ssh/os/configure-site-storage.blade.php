set -eu

VITO_STORAGE_ROOT={!! escapeshellarg($storageRoot) !!}
VITO_STORAGE_MARKER=/var/lib/vito/site-storage-ready

sudo DEBIAN_FRONTEND=noninteractive apt-get install -y quota xfsprogs >/dev/null
sudo install -d -o root -g root -m 0755 "$VITO_STORAGE_ROOT"

VITO_MOUNT_POINT="$(findmnt -no TARGET -T "$VITO_STORAGE_ROOT")"
VITO_FILESYSTEM="$(findmnt -no FSTYPE -T "$VITO_STORAGE_ROOT")"

case "$VITO_FILESYSTEM" in
    ext4)
        VITO_QUOTA_OPTION=usrquota
        ;;
    xfs)
        VITO_QUOTA_OPTION=uquota
        ;;
    *)
        echo "VITO_SSH_ERROR: site storage requires ext4 or XFS, found $VITO_FILESYSTEM" && exit 1
        ;;
esac

VITO_OPTIONS="$(findmnt -no OPTIONS -T "$VITO_STORAGE_ROOT")"
case ",$VITO_OPTIONS," in
    *,usrquota,*|*,uquota,*) ;;
    *)
        VITO_FSTAB_CANDIDATE="$(mktemp)"
        VITO_FSTAB_MATCHES="$(sudo awk -v target="$VITO_MOUNT_POINT" '$1 !~ /^#/ && $2 == target { matches++ } END { print matches + 0 }' /etc/fstab)"
        if [ "$VITO_FSTAB_MATCHES" != 1 ]; then
            rm -f -- "$VITO_FSTAB_CANDIDATE"
            echo 'VITO_SSH_ERROR: site storage mount must have exactly one fstab entry' && exit 1
        fi
        sudo awk -v target="$VITO_MOUNT_POINT" -v quota_option="$VITO_QUOTA_OPTION" '
            $1 !~ /^#/ && $2 == target {
                split($4, options, ",")
                present = 0
                for (i in options) {
                    if (options[i] == quota_option) { present = 1 }
                }
                if (!present) { $4 = $4 "," quota_option }
            }
            { print }
        ' /etc/fstab > "$VITO_FSTAB_CANDIDATE"
        if ! sudo findmnt --verify --tab-file "$VITO_FSTAB_CANDIDATE" >/dev/null; then
            rm -f -- "$VITO_FSTAB_CANDIDATE"
            echo 'VITO_SSH_ERROR: generated site storage fstab configuration is invalid' && exit 1
        fi
        sudo cp -a /etc/fstab /etc/fstab.vito-before-site-storage
        sudo install -o root -g root -m 0644 "$VITO_FSTAB_CANDIDATE" /etc/fstab
        rm -f -- "$VITO_FSTAB_CANDIDATE"
        if ! sudo mount -o remount,"$VITO_QUOTA_OPTION" "$VITO_MOUNT_POINT"; then
            sudo install -o root -g root -m 0644 /etc/fstab.vito-before-site-storage /etc/fstab
            echo 'VITO_SSH_ERROR: site storage quota remount failed' && exit 1
        fi
        ;;
esac

if [ "$VITO_FILESYSTEM" = ext4 ]; then
    sudo quotaoff -u "$VITO_MOUNT_POINT" >/dev/null 2>&1 || true
    sudo quotacheck -cumu "$VITO_MOUNT_POINT" >/dev/null
fi

sudo quotaon -u "$VITO_MOUNT_POINT" >/dev/null
VITO_QUOTA_UNIT=/etc/systemd/system/vito-site-storage-quota.service
if ! printf '[Unit]\nDescription=Enable Vito site storage quotas\nAfter=local-fs.target\n\n[Service]\nType=oneshot\nExecStart=/usr/sbin/quotaon -u %s\n\n[Install]\nWantedBy=multi-user.target\n' "$VITO_MOUNT_POINT" | sudo tee "$VITO_QUOTA_UNIT" >/dev/null; then
    echo 'VITO_SSH_ERROR: site storage quota boot unit could not be written' && exit 1
fi
sudo chmod 0644 "$VITO_QUOTA_UNIT"
sudo systemctl daemon-reload
sudo systemctl enable vito-site-storage-quota.service >/dev/null
sudo install -d -o root -g root -m 0700 /var/lib/vito
sudo touch "$VITO_STORAGE_MARKER"
sudo chown root:root "$VITO_STORAGE_MARKER"
sudo chmod 0600 "$VITO_STORAGE_MARKER"

unset VITO_STORAGE_ROOT VITO_STORAGE_MARKER VITO_MOUNT_POINT VITO_FILESYSTEM VITO_QUOTA_OPTION VITO_OPTIONS VITO_FSTAB_CANDIDATE VITO_FSTAB_MATCHES VITO_QUOTA_UNIT
