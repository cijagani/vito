VITO_TARGET={!! escapeshellarg($targetPath) !!}
VITO_PREVIOUS={!! escapeshellarg($stateDirectory.'/previous') !!}
VITO_FPM_BINARY={!! escapeshellarg($fpmBinary) !!}
VITO_SERVICE={!! escapeshellarg($serviceUnit) !!}

if ! sudo test -d "$VITO_PREVIOUS"; then
    echo 'VITO_SSH_ERROR: no previous PHP-FPM configuration is available for rollback' && exit 1
fi

sudo rm -f -- "$VITO_TARGET.next" "$VITO_TARGET"
if sudo test -e "$VITO_PREVIOUS/target"; then
    sudo cp -a -- "$VITO_PREVIOUS/target" "$VITO_TARGET"
fi

if ! sudo "$VITO_FPM_BINARY" -t; then
    echo 'VITO_SSH_ERROR: restored PHP-FPM configuration is invalid' && exit 1
fi
if ! sudo systemctl reload "$VITO_SERVICE"; then
    echo 'VITO_SSH_ERROR: restored PHP-FPM configuration could not be reloaded' && exit 1
fi

unset VITO_TARGET VITO_PREVIOUS VITO_FPM_BINARY VITO_SERVICE
