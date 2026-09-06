VITO_TARGET={!! escapeshellarg($targetPath) !!}
VITO_ENABLED={!! escapeshellarg($enabledPath) !!}
VITO_LEGACY_TARGET={!! escapeshellarg($legacyAvailablePath) !!}
VITO_LEGACY_ENABLED={!! escapeshellarg($legacyEnabledPath) !!}
VITO_PREVIOUS={!! escapeshellarg($stateDirectory.'/previous') !!}

vito_restore_nginx_path() {
    VITO_DESTINATION="$1"
    VITO_NAME="$2"
    sudo rm -f -- "$VITO_DESTINATION"
    if sudo test -e "$VITO_PREVIOUS/$VITO_NAME" || sudo test -L "$VITO_PREVIOUS/$VITO_NAME"; then
        sudo cp -a -- "$VITO_PREVIOUS/$VITO_NAME" "$VITO_DESTINATION"
    fi
}

if ! sudo test -d "$VITO_PREVIOUS"; then
    echo 'VITO_SSH_ERROR: no previous nginx configuration is available for rollback' && exit 1
fi

sudo rm -f -- "$VITO_TARGET.next" "$VITO_ENABLED.next"
vito_restore_nginx_path "$VITO_TARGET" target
vito_restore_nginx_path "$VITO_ENABLED" enabled
vito_restore_nginx_path "$VITO_LEGACY_TARGET" legacy-target
vito_restore_nginx_path "$VITO_LEGACY_ENABLED" legacy-enabled

if ! sudo nginx -t; then
    echo 'VITO_SSH_ERROR: restored nginx configuration is invalid' && exit 1
fi

if ! sudo systemctl reload nginx; then
    echo 'VITO_SSH_ERROR: restored nginx configuration could not be reloaded' && exit 1
fi

unset VITO_TARGET VITO_ENABLED VITO_LEGACY_TARGET VITO_LEGACY_ENABLED VITO_PREVIOUS VITO_DESTINATION VITO_NAME
