VITO_CANDIDATE={!! escapeshellarg($candidatePath) !!}
VITO_TARGET={!! escapeshellarg($targetPath) !!}
VITO_ENABLED={!! escapeshellarg($enabledPath) !!}
VITO_LEGACY_TARGET={!! escapeshellarg($legacyAvailablePath) !!}
VITO_LEGACY_ENABLED={!! escapeshellarg($legacyEnabledPath) !!}
VITO_PREVIOUS={!! escapeshellarg($stateDirectory.'/previous') !!}
VITO_SERVICE_ACTION={!! escapeshellarg($serviceAction) !!}

vito_backup_nginx_path() {
    VITO_SOURCE="$1"
    VITO_NAME="$2"
    if sudo test -e "$VITO_SOURCE" || sudo test -L "$VITO_SOURCE"; then
        sudo cp -a -- "$VITO_SOURCE" "$VITO_PREVIOUS/$VITO_NAME"
    else
        sudo touch "$VITO_PREVIOUS/$VITO_NAME.missing"
    fi
}

vito_restore_nginx_path() {
    VITO_DESTINATION="$1"
    VITO_NAME="$2"
    sudo rm -f -- "$VITO_DESTINATION" || return 1
    if sudo test -e "$VITO_PREVIOUS/$VITO_NAME" || sudo test -L "$VITO_PREVIOUS/$VITO_NAME"; then
        sudo cp -a -- "$VITO_PREVIOUS/$VITO_NAME" "$VITO_DESTINATION" || return 1
    fi
}

vito_restore_previous_nginx() {
    VITO_RESTORE_STATUS=0
    sudo rm -f -- "$VITO_TARGET.next" "$VITO_ENABLED.next" || VITO_RESTORE_STATUS=1
    vito_restore_nginx_path "$VITO_TARGET" target || VITO_RESTORE_STATUS=1
    vito_restore_nginx_path "$VITO_ENABLED" enabled || VITO_RESTORE_STATUS=1
    vito_restore_nginx_path "$VITO_LEGACY_TARGET" legacy-target || VITO_RESTORE_STATUS=1
    vito_restore_nginx_path "$VITO_LEGACY_ENABLED" legacy-enabled || VITO_RESTORE_STATUS=1
    return "$VITO_RESTORE_STATUS"
}

vito_fail_nginx_apply() {
    VITO_FAILURE="$1"
    if ! vito_restore_previous_nginx; then
        echo 'VITO_SSH_ERROR: nginx activation failed and the previous files could not be restored' && exit 1
    fi
    if ! sudo nginx -t; then
        echo 'VITO_SSH_ERROR: nginx activation failed and the restored configuration is invalid' && exit 1
    fi
    sudo systemctl reload nginx || true
    echo "VITO_SSH_ERROR: $VITO_FAILURE" && exit 1
}

sudo rm -rf -- "$VITO_PREVIOUS"
sudo install -d -o root -g root -m 0700 "$VITO_PREVIOUS"
vito_backup_nginx_path "$VITO_TARGET" target
vito_backup_nginx_path "$VITO_ENABLED" enabled
vito_backup_nginx_path "$VITO_LEGACY_TARGET" legacy-target
vito_backup_nginx_path "$VITO_LEGACY_ENABLED" legacy-enabled

sudo install -o root -g root -m 0644 "$VITO_CANDIDATE" "$VITO_TARGET.next" || vito_fail_nginx_apply 'nginx candidate could not be staged'
sudo mv -f -- "$VITO_TARGET.next" "$VITO_TARGET" || vito_fail_nginx_apply 'nginx candidate could not be activated'
sudo ln -sfn "$VITO_TARGET" "$VITO_ENABLED.next" || vito_fail_nginx_apply 'nginx vhost could not be enabled'
sudo mv -Tf -- "$VITO_ENABLED.next" "$VITO_ENABLED" || vito_fail_nginx_apply 'nginx vhost could not be enabled'
sudo rm -f -- "$VITO_LEGACY_TARGET" "$VITO_LEGACY_ENABLED" || vito_fail_nginx_apply 'legacy nginx vhost could not be retired'

sudo nginx -t || vito_fail_nginx_apply 'nginx activation validation failed'
sudo systemctl "$VITO_SERVICE_ACTION" nginx || vito_fail_nginx_apply "nginx $VITO_SERVICE_ACTION failed"

unset VITO_CANDIDATE VITO_TARGET VITO_ENABLED VITO_LEGACY_TARGET VITO_LEGACY_ENABLED VITO_PREVIOUS VITO_SERVICE_ACTION VITO_SOURCE VITO_NAME VITO_DESTINATION VITO_RESTORE_STATUS VITO_FAILURE
