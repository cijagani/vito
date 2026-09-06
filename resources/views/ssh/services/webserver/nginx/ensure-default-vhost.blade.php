VITO_DEFAULT_CANDIDATE={!! escapeshellarg($candidatePath) !!}
VITO_DEFAULT_TARGET='/etc/nginx/sites-available/000-default'
VITO_DEFAULT_ENABLED='/etc/nginx/sites-enabled/000-default'
VITO_DEFAULT_STATE='/var/lib/vito/nginx/default-vhost'
VITO_DEFAULT_EXISTED=0
VITO_DEFAULT_ENABLED_EXISTED=0

vito_restore_default_vhost() {
    sudo rm -f -- "$VITO_DEFAULT_TARGET" "$VITO_DEFAULT_ENABLED" "$VITO_DEFAULT_TARGET.next" "$VITO_DEFAULT_ENABLED.next" || true
    if [ "$VITO_DEFAULT_EXISTED" = '1' ]; then
        sudo cp -a -- "$VITO_DEFAULT_STATE/target" "$VITO_DEFAULT_TARGET" || true
    fi
    if [ "$VITO_DEFAULT_ENABLED_EXISTED" = '1' ]; then
        sudo cp -a -- "$VITO_DEFAULT_STATE/enabled" "$VITO_DEFAULT_ENABLED" || true
    fi
}

vito_fail_default_vhost() {
    vito_restore_default_vhost
    echo "VITO_SSH_ERROR: $1" && exit 1
}

if sudo test -f "$VITO_DEFAULT_TARGET" \
    && sudo cmp -s "$VITO_DEFAULT_CANDIDATE" "$VITO_DEFAULT_TARGET" \
    && [ "$(sudo readlink "$VITO_DEFAULT_ENABLED" 2>/dev/null || true)" = "$VITO_DEFAULT_TARGET" ]; then
    sudo rm -f -- "$VITO_DEFAULT_CANDIDATE" || true
else
    sudo rm -rf -- "$VITO_DEFAULT_STATE"
    sudo install -d -o root -g root -m 0700 "$VITO_DEFAULT_STATE"
    if sudo test -e "$VITO_DEFAULT_TARGET" || sudo test -L "$VITO_DEFAULT_TARGET"; then
        sudo cp -a -- "$VITO_DEFAULT_TARGET" "$VITO_DEFAULT_STATE/target"
        VITO_DEFAULT_EXISTED=1
    fi
    if sudo test -e "$VITO_DEFAULT_ENABLED" || sudo test -L "$VITO_DEFAULT_ENABLED"; then
        sudo cp -a -- "$VITO_DEFAULT_ENABLED" "$VITO_DEFAULT_STATE/enabled"
        VITO_DEFAULT_ENABLED_EXISTED=1
    fi

    sudo install -o root -g root -m 0644 "$VITO_DEFAULT_CANDIDATE" "$VITO_DEFAULT_TARGET.next" || vito_fail_default_vhost 'nginx default vhost could not be staged'
    sudo mv -f -- "$VITO_DEFAULT_TARGET.next" "$VITO_DEFAULT_TARGET" || vito_fail_default_vhost 'nginx default vhost could not be activated'
    sudo ln -sfn "$VITO_DEFAULT_TARGET" "$VITO_DEFAULT_ENABLED.next" || vito_fail_default_vhost 'nginx default vhost could not be enabled'
    sudo mv -Tf -- "$VITO_DEFAULT_ENABLED.next" "$VITO_DEFAULT_ENABLED" || vito_fail_default_vhost 'nginx default vhost could not be enabled'

    if ! sudo nginx -t; then
        vito_restore_default_vhost
        echo 'VITO_SSH_ERROR: nginx default vhost is invalid' && exit 1
    fi
    if ! sudo systemctl reload nginx; then
        vito_restore_default_vhost
        sudo nginx -t && sudo systemctl reload nginx || true
        echo 'VITO_SSH_ERROR: nginx default vhost could not be activated' && exit 1
    fi

    sudo rm -rf -- "$VITO_DEFAULT_STATE" || true
    sudo rm -f -- "$VITO_DEFAULT_CANDIDATE" || true
fi

unset VITO_DEFAULT_CANDIDATE VITO_DEFAULT_TARGET VITO_DEFAULT_ENABLED VITO_DEFAULT_STATE VITO_DEFAULT_EXISTED VITO_DEFAULT_ENABLED_EXISTED
