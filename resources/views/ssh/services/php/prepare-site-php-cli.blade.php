VITO_SITE_USER={!! escapeshellarg($siteUser) !!}
VITO_HOME={!! escapeshellarg($homeDirectory) !!}
VITO_RUNTIME_PATH={!! escapeshellarg($runtimeDirectory) !!}

if ! id -u "$VITO_SITE_USER" >/dev/null 2>&1; then
    echo 'VITO_SSH_ERROR: site identity is missing' && exit 1
fi

sudo install -d -o root -g root -m 0755 /var/lib/vito /var/lib/vito/php-cli
sudo install -d -o root -g "$VITO_SITE_USER" -m 0750 "$VITO_RUNTIME_PATH"
sudo install -d -o "$VITO_SITE_USER" -g "$VITO_SITE_USER" -m 0750 "$VITO_HOME/bin"

unset VITO_SITE_USER VITO_HOME VITO_RUNTIME_PATH
