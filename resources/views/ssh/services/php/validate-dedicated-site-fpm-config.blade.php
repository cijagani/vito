VITO_CANDIDATE={!! escapeshellarg($candidatePath) !!}
VITO_SERVICE_CANDIDATE={!! escapeshellarg($serviceCandidatePath) !!}
VITO_SLICE_CANDIDATE={!! escapeshellarg($sliceCandidatePath) !!}
VITO_TARGET={!! escapeshellarg($targetPath) !!}
VITO_SERVICE_PATH={!! escapeshellarg($servicePath) !!}
VITO_SLICE_PATH={!! escapeshellarg($slicePath) !!}
VITO_VALIDATION={!! escapeshellarg($stateDirectory.'/validation') !!}
VITO_FPM_BINARY={!! escapeshellarg($fpmBinary) !!}

vito_restore_dedicated_fpm_validation() {
    sudo rm -f -- "$VITO_TARGET.next" "$VITO_TARGET" "$VITO_SERVICE_PATH.next" "$VITO_SERVICE_PATH" "$VITO_SLICE_PATH.next" "$VITO_SLICE_PATH"
    if sudo test -e "$VITO_VALIDATION/target"; then sudo cp -a -- "$VITO_VALIDATION/target" "$VITO_TARGET"; fi
    if sudo test -e "$VITO_VALIDATION/service"; then sudo cp -a -- "$VITO_VALIDATION/service" "$VITO_SERVICE_PATH"; fi
    if sudo test -e "$VITO_VALIDATION/slice"; then sudo cp -a -- "$VITO_VALIDATION/slice" "$VITO_SLICE_PATH"; fi
    sudo systemctl daemon-reload || true
    sudo rm -rf -- "$VITO_VALIDATION"
}

sudo rm -rf -- "$VITO_VALIDATION"
sudo install -d -o root -g root -m 0700 "$VITO_VALIDATION"
if sudo test -e "$VITO_TARGET"; then sudo cp -a -- "$VITO_TARGET" "$VITO_VALIDATION/target"; fi
if sudo test -e "$VITO_SERVICE_PATH"; then sudo cp -a -- "$VITO_SERVICE_PATH" "$VITO_VALIDATION/service"; fi
if sudo test -e "$VITO_SLICE_PATH"; then sudo cp -a -- "$VITO_SLICE_PATH" "$VITO_VALIDATION/slice"; fi
trap vito_restore_dedicated_fpm_validation EXIT

sudo install -d -o root -g root -m 0755 "$(dirname "$VITO_TARGET")"
sudo install -o root -g root -m 0644 "$VITO_CANDIDATE" "$VITO_TARGET.next"
sudo install -o root -g root -m 0644 "$VITO_SERVICE_CANDIDATE" "$VITO_SERVICE_PATH.next"
sudo install -o root -g root -m 0644 "$VITO_SLICE_CANDIDATE" "$VITO_SLICE_PATH.next"
sudo mv -f -- "$VITO_TARGET.next" "$VITO_TARGET"
sudo mv -f -- "$VITO_SERVICE_PATH.next" "$VITO_SERVICE_PATH"
sudo mv -f -- "$VITO_SLICE_PATH.next" "$VITO_SLICE_PATH"
if ! sudo "$VITO_FPM_BINARY" -t -y "$VITO_TARGET"; then
    echo 'VITO_SSH_ERROR: dedicated PHP-FPM candidate configuration is invalid' && exit 1
fi
if ! sudo systemd-analyze verify "$VITO_SERVICE_PATH" "$VITO_SLICE_PATH"; then
    echo 'VITO_SSH_ERROR: dedicated PHP-FPM systemd configuration is invalid' && exit 1
fi

vito_restore_dedicated_fpm_validation
trap - EXIT
unset VITO_CANDIDATE VITO_SERVICE_CANDIDATE VITO_SLICE_CANDIDATE VITO_TARGET VITO_SERVICE_PATH VITO_SLICE_PATH VITO_VALIDATION VITO_FPM_BINARY
