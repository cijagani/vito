set -eu

VITO_LOGROTATE_PATH={!! escapeshellarg($logrotatePath) !!}
case "$VITO_LOGROTATE_PATH" in
    /etc/logrotate.d/vito-site-[0-9]*) ;;
    *) echo 'VITO_SSH_ERROR: refusing to delete an unsafe logrotate configuration' && exit 1 ;;
esac

sudo rm -f -- "$VITO_LOGROTATE_PATH"
unset VITO_LOGROTATE_PATH
