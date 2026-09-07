VITO_CANDIDATE={!! escapeshellarg($candidatePath) !!}
VITO_TARGET={!! escapeshellarg($targetPath) !!}
VITO_PREVIOUS={!! escapeshellarg($stateDirectory.'/previous') !!}
VITO_FPM_BINARY={!! escapeshellarg($fpmBinary) !!}
VITO_SERVICE={!! escapeshellarg($serviceUnit) !!}

vito_restore_previous_fpm() {
    sudo rm -f -- "$VITO_TARGET.next" "$VITO_TARGET"
    if sudo test -e "$VITO_PREVIOUS/target"; then
        sudo cp -a -- "$VITO_PREVIOUS/target" "$VITO_TARGET"
    fi
}

vito_fail_fpm_apply() {
    VITO_FAILURE="$1"
    vito_restore_previous_fpm
    if ! sudo "$VITO_FPM_BINARY" -t; then
        echo 'VITO_SSH_ERROR: PHP-FPM activation failed and the restored configuration is invalid' && exit 1
    fi
    sudo systemctl reload "$VITO_SERVICE" || true
    echo "VITO_SSH_ERROR: $VITO_FAILURE" && exit 1
}

sudo rm -rf -- "$VITO_PREVIOUS"
sudo install -d -o root -g root -m 0700 "$VITO_PREVIOUS"
if sudo test -e "$VITO_TARGET"; then
    sudo cp -a -- "$VITO_TARGET" "$VITO_PREVIOUS/target"
fi

sudo install -o root -g root -m 0644 "$VITO_CANDIDATE" "$VITO_TARGET.next" || vito_fail_fpm_apply 'PHP-FPM candidate could not be staged'
sudo mv -f -- "$VITO_TARGET.next" "$VITO_TARGET" || vito_fail_fpm_apply 'PHP-FPM candidate could not be activated'
sudo "$VITO_FPM_BINARY" -t || vito_fail_fpm_apply 'PHP-FPM activation validation failed'
sudo systemctl reload "$VITO_SERVICE" || vito_fail_fpm_apply 'PHP-FPM reload failed'

unset VITO_CANDIDATE VITO_TARGET VITO_PREVIOUS VITO_FPM_BINARY VITO_SERVICE VITO_FAILURE
