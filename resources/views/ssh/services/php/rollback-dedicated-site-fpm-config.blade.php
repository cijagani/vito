VITO_TARGET={!! escapeshellarg($targetPath) !!}
VITO_SERVICE_PATH={!! escapeshellarg($servicePath) !!}
VITO_SLICE_PATH={!! escapeshellarg($slicePath) !!}
VITO_PREVIOUS={!! escapeshellarg($stateDirectory.'/previous') !!}
VITO_FPM_BINARY={!! escapeshellarg($fpmBinary) !!}
VITO_SERVICE={!! escapeshellarg($serviceUnit) !!}

if ! sudo test -d "$VITO_PREVIOUS"; then
    echo 'VITO_SSH_ERROR: no previous dedicated PHP-FPM configuration is available for rollback' && exit 1
fi

sudo systemctl stop "$VITO_SERVICE" || true
sudo rm -f -- "$VITO_TARGET.next" "$VITO_TARGET" "$VITO_SERVICE_PATH.next" "$VITO_SERVICE_PATH" "$VITO_SLICE_PATH.next" "$VITO_SLICE_PATH"
if sudo test -e "$VITO_PREVIOUS/target"; then sudo cp -a -- "$VITO_PREVIOUS/target" "$VITO_TARGET"; fi
if sudo test -e "$VITO_PREVIOUS/service"; then sudo cp -a -- "$VITO_PREVIOUS/service" "$VITO_SERVICE_PATH"; fi
if sudo test -e "$VITO_PREVIOUS/slice"; then sudo cp -a -- "$VITO_PREVIOUS/slice" "$VITO_SLICE_PATH"; fi
sudo systemctl daemon-reload || { echo 'VITO_SSH_ERROR: restored systemd configuration could not be reloaded' && exit 1; }
if sudo test -e "$VITO_PREVIOUS/service"; then
    sudo "$VITO_FPM_BINARY" -t -y "$VITO_TARGET" || { echo 'VITO_SSH_ERROR: restored dedicated PHP-FPM configuration is invalid' && exit 1; }
    sudo systemctl enable --now "$VITO_SERVICE" || { echo 'VITO_SSH_ERROR: restored dedicated PHP-FPM configuration could not be enabled and started' && exit 1; }
fi

unset VITO_TARGET VITO_SERVICE_PATH VITO_SLICE_PATH VITO_PREVIOUS VITO_FPM_BINARY VITO_SERVICE
