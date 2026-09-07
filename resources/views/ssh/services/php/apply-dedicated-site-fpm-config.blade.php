VITO_CANDIDATE={!! escapeshellarg($candidatePath) !!}
VITO_SERVICE_CANDIDATE={!! escapeshellarg($serviceCandidatePath) !!}
VITO_SLICE_CANDIDATE={!! escapeshellarg($sliceCandidatePath) !!}
VITO_TARGET={!! escapeshellarg($targetPath) !!}
VITO_SERVICE_PATH={!! escapeshellarg($servicePath) !!}
VITO_SLICE_PATH={!! escapeshellarg($slicePath) !!}
VITO_PREVIOUS={!! escapeshellarg($stateDirectory.'/previous') !!}
VITO_FPM_BINARY={!! escapeshellarg($fpmBinary) !!}
VITO_SERVICE={!! escapeshellarg($serviceUnit) !!}

vito_restore_previous_dedicated_fpm() {
    sudo systemctl stop "$VITO_SERVICE" || true
    sudo rm -f -- "$VITO_TARGET.next" "$VITO_TARGET" "$VITO_SERVICE_PATH.next" "$VITO_SERVICE_PATH" "$VITO_SLICE_PATH.next" "$VITO_SLICE_PATH"
    if sudo test -e "$VITO_PREVIOUS/target"; then sudo cp -a -- "$VITO_PREVIOUS/target" "$VITO_TARGET"; fi
    if sudo test -e "$VITO_PREVIOUS/service"; then sudo cp -a -- "$VITO_PREVIOUS/service" "$VITO_SERVICE_PATH"; fi
    if sudo test -e "$VITO_PREVIOUS/slice"; then sudo cp -a -- "$VITO_PREVIOUS/slice" "$VITO_SLICE_PATH"; fi
    sudo systemctl daemon-reload || true
    if sudo test -e "$VITO_PREVIOUS/service"; then sudo systemctl enable --now "$VITO_SERVICE" || true; fi
}

vito_fail_dedicated_fpm_apply() {
    vito_restore_previous_dedicated_fpm
    echo "VITO_SSH_ERROR: $1" && exit 1
}

sudo rm -rf -- "$VITO_PREVIOUS"
sudo install -d -o root -g root -m 0700 "$VITO_PREVIOUS"
if sudo test -e "$VITO_TARGET"; then sudo cp -a -- "$VITO_TARGET" "$VITO_PREVIOUS/target"; fi
if sudo test -e "$VITO_SERVICE_PATH"; then sudo cp -a -- "$VITO_SERVICE_PATH" "$VITO_PREVIOUS/service"; fi
if sudo test -e "$VITO_SLICE_PATH"; then sudo cp -a -- "$VITO_SLICE_PATH" "$VITO_PREVIOUS/slice"; fi

sudo install -d -o root -g root -m 0755 "$(dirname "$VITO_TARGET")" || vito_fail_dedicated_fpm_apply 'Dedicated PHP-FPM directory could not be created'
sudo install -o root -g root -m 0644 "$VITO_CANDIDATE" "$VITO_TARGET.next" || vito_fail_dedicated_fpm_apply 'Dedicated PHP-FPM candidate could not be staged'
sudo install -o root -g root -m 0644 "$VITO_SERVICE_CANDIDATE" "$VITO_SERVICE_PATH.next" || vito_fail_dedicated_fpm_apply 'Dedicated PHP-FPM service could not be staged'
sudo install -o root -g root -m 0644 "$VITO_SLICE_CANDIDATE" "$VITO_SLICE_PATH.next" || vito_fail_dedicated_fpm_apply 'Dedicated PHP-FPM slice could not be staged'
sudo mv -f -- "$VITO_TARGET.next" "$VITO_TARGET" || vito_fail_dedicated_fpm_apply 'Dedicated PHP-FPM candidate could not be activated'
sudo mv -f -- "$VITO_SERVICE_PATH.next" "$VITO_SERVICE_PATH" || vito_fail_dedicated_fpm_apply 'Dedicated PHP-FPM service could not be activated'
sudo mv -f -- "$VITO_SLICE_PATH.next" "$VITO_SLICE_PATH" || vito_fail_dedicated_fpm_apply 'Dedicated PHP-FPM slice could not be activated'
sudo "$VITO_FPM_BINARY" -t -y "$VITO_TARGET" || vito_fail_dedicated_fpm_apply 'Dedicated PHP-FPM activation validation failed'
sudo systemctl daemon-reload || vito_fail_dedicated_fpm_apply 'systemd reload failed'
sudo systemctl enable --now "$VITO_SERVICE" || vito_fail_dedicated_fpm_apply 'Dedicated PHP-FPM service could not be enabled and started'

unset VITO_CANDIDATE VITO_SERVICE_CANDIDATE VITO_SLICE_CANDIDATE VITO_TARGET VITO_SERVICE_PATH VITO_SLICE_PATH VITO_PREVIOUS VITO_FPM_BINARY VITO_SERVICE
