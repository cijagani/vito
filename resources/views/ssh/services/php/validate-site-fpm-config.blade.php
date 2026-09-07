VITO_CANDIDATE={!! escapeshellarg($candidatePath) !!}
VITO_TARGET={!! escapeshellarg($targetPath) !!}
VITO_VALIDATION={!! escapeshellarg($stateDirectory.'/validation') !!}
VITO_FPM_BINARY={!! escapeshellarg($fpmBinary) !!}

vito_restore_fpm_validation() {
    sudo rm -f -- "$VITO_TARGET.next" "$VITO_TARGET"
    if sudo test -e "$VITO_VALIDATION/target"; then
        sudo cp -a -- "$VITO_VALIDATION/target" "$VITO_TARGET"
    fi
    sudo rm -rf -- "$VITO_VALIDATION"
}

sudo rm -rf -- "$VITO_VALIDATION"
sudo install -d -o root -g root -m 0700 "$VITO_VALIDATION"
if sudo test -e "$VITO_TARGET"; then
    sudo cp -a -- "$VITO_TARGET" "$VITO_VALIDATION/target"
fi
trap vito_restore_fpm_validation EXIT

sudo install -o root -g root -m 0644 "$VITO_CANDIDATE" "$VITO_TARGET.next"
sudo mv -f -- "$VITO_TARGET.next" "$VITO_TARGET"
if ! sudo "$VITO_FPM_BINARY" -t; then
    echo 'VITO_SSH_ERROR: PHP-FPM candidate configuration is invalid' && exit 1
fi

vito_restore_fpm_validation
trap - EXIT
unset VITO_CANDIDATE VITO_TARGET VITO_VALIDATION VITO_FPM_BINARY
