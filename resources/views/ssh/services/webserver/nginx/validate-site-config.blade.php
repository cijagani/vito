VITO_CANDIDATE={!! escapeshellarg($candidatePath) !!}
VITO_TARGET={!! escapeshellarg($targetPath) !!}
VITO_ENABLED={!! escapeshellarg($enabledPath) !!}
VITO_LEGACY_ENABLED={!! escapeshellarg($legacyEnabledPath) !!}
VITO_VALIDATION={!! escapeshellarg($stateDirectory.'/validation') !!}

vito_backup_validation_path() {
    VITO_SOURCE="$1"
    VITO_NAME="$2"
    if sudo test -e "$VITO_SOURCE" || sudo test -L "$VITO_SOURCE"; then
        sudo cp -a -- "$VITO_SOURCE" "$VITO_VALIDATION/$VITO_NAME"
    else
        sudo touch "$VITO_VALIDATION/$VITO_NAME.missing"
    fi
}

vito_restore_validation_path() {
    VITO_DESTINATION="$1"
    VITO_NAME="$2"
    sudo rm -f -- "$VITO_DESTINATION" || true
    if sudo test -e "$VITO_VALIDATION/$VITO_NAME" || sudo test -L "$VITO_VALIDATION/$VITO_NAME"; then
        sudo cp -a -- "$VITO_VALIDATION/$VITO_NAME" "$VITO_DESTINATION" || true
    fi
}

vito_restore_validation() {
    sudo rm -f -- "$VITO_TARGET.next" "$VITO_ENABLED.next" || true
    vito_restore_validation_path "$VITO_TARGET" target
    vito_restore_validation_path "$VITO_ENABLED" enabled
    vito_restore_validation_path "$VITO_LEGACY_ENABLED" legacy-enabled
    sudo rm -rf -- "$VITO_VALIDATION" || true
}

sudo rm -rf -- "$VITO_VALIDATION"
sudo install -d -o root -g root -m 0700 "$VITO_VALIDATION"
vito_backup_validation_path "$VITO_TARGET" target
vito_backup_validation_path "$VITO_ENABLED" enabled
vito_backup_validation_path "$VITO_LEGACY_ENABLED" legacy-enabled
trap vito_restore_validation EXIT

sudo install -o root -g root -m 0644 "$VITO_CANDIDATE" "$VITO_TARGET.next"
sudo mv -f -- "$VITO_TARGET.next" "$VITO_TARGET"
sudo ln -sfn "$VITO_TARGET" "$VITO_ENABLED.next"
sudo mv -Tf -- "$VITO_ENABLED.next" "$VITO_ENABLED"
sudo rm -f -- "$VITO_LEGACY_ENABLED"

if ! sudo nginx -t; then
    echo 'VITO_SSH_ERROR: nginx candidate configuration is invalid' && exit 1
fi

vito_restore_validation
trap - EXIT
unset VITO_CANDIDATE VITO_TARGET VITO_ENABLED VITO_LEGACY_ENABLED VITO_VALIDATION VITO_SOURCE VITO_NAME VITO_DESTINATION
