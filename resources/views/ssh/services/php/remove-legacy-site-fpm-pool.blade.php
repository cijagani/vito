VITO_TARGET={!! escapeshellarg($targetPath) !!}
VITO_STATE_PATH={!! escapeshellarg($stateDirectory) !!}
VITO_REMOVAL_BACKUP={!! escapeshellarg($stateDirectory.'/legacy-removal') !!}
VITO_FPM_BINARY={!! escapeshellarg($fpmBinary) !!}
VITO_SERVICE={!! escapeshellarg($serviceUnit) !!}

if sudo test -e "$VITO_TARGET"; then
    sudo install -d -o root -g root -m 0700 "$VITO_STATE_PATH"
    sudo rm -rf -- "$VITO_REMOVAL_BACKUP"
    sudo install -d -o root -g root -m 0700 "$VITO_REMOVAL_BACKUP"
    sudo cp -a -- "$VITO_TARGET" "$VITO_REMOVAL_BACKUP/target"
    sudo rm -f -- "$VITO_TARGET"
    if ! sudo "$VITO_FPM_BINARY" -t; then
        sudo cp -a -- "$VITO_REMOVAL_BACKUP/target" "$VITO_TARGET"
        echo 'VITO_SSH_ERROR: PHP-FPM configuration is invalid after retiring the legacy pool' && exit 1
    fi
    if ! sudo systemctl reload "$VITO_SERVICE"; then
        sudo cp -a -- "$VITO_REMOVAL_BACKUP/target" "$VITO_TARGET"
        sudo "$VITO_FPM_BINARY" -t && sudo systemctl reload "$VITO_SERVICE" || true
        echo 'VITO_SSH_ERROR: PHP-FPM could not be reloaded after retiring the legacy pool' && exit 1
    fi
    sudo rm -rf -- "$VITO_REMOVAL_BACKUP"
fi

unset VITO_TARGET VITO_STATE_PATH VITO_REMOVAL_BACKUP VITO_FPM_BINARY VITO_SERVICE
