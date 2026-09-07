set -eu

VITO_SITE_USER={!! escapeshellarg($siteUser) !!}
VITO_HOME={!! escapeshellarg($homeDirectory) !!}
VITO_STORAGE_ROOT={!! escapeshellarg($storageRoot) !!}
VITO_STORAGE_MARKER=/var/lib/vito/site-storage-ready
VITO_QUOTA_MB={!! $quotaMb === null ? "''" : escapeshellarg((string) $quotaMb) !!}

if ! id -u "$VITO_SITE_USER" >/dev/null 2>&1; then
    echo 'VITO_SSH_ERROR: site identity is missing' && exit 1
fi
if [ ! -f "$VITO_STORAGE_MARKER" ] || [ ! -d "$VITO_HOME" ]; then
    echo 'VITO_SSH_ERROR: quota-enabled site storage is not ready' && exit 1
fi

VITO_MOUNT_POINT="$(findmnt -no TARGET -T "$VITO_STORAGE_ROOT")"
if [ "$(findmnt -no TARGET -T "$VITO_HOME")" != "$VITO_MOUNT_POINT" ]; then
    echo 'VITO_SSH_ERROR: site home is outside the quota-enabled storage filesystem' && exit 1
fi

if [ -n "$VITO_QUOTA_MB" ]; then
    VITO_BLOCKS=$((VITO_QUOTA_MB * 1024))
else
    VITO_BLOCKS=0
fi

sudo setquota -u "$VITO_SITE_USER" 0 "$VITO_BLOCKS" 0 0 "$VITO_MOUNT_POINT"

unset VITO_SITE_USER VITO_HOME VITO_STORAGE_ROOT VITO_STORAGE_MARKER VITO_QUOTA_MB VITO_MOUNT_POINT VITO_BLOCKS
