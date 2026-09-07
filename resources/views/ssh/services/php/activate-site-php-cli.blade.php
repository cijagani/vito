VITO_SITE_USER={!! escapeshellarg($siteUser) !!}
VITO_HOME={!! escapeshellarg($homeDirectory) !!}
VITO_PHP_BINARY={!! escapeshellarg($phpBinary) !!}
VITO_RUNTIME_PATH={!! escapeshellarg($runtimeDirectory) !!}
VITO_PROFILE={!! escapeshellarg($profilePath) !!}
VITO_INI_SCAN_DIR={!! escapeshellarg($iniScanDirectory) !!}
VITO_PROFILE_LINE="[ -r $VITO_PROFILE ] && . $VITO_PROFILE"

if ! sudo test -x "$VITO_PHP_BINARY"; then
    echo 'VITO_SSH_ERROR: selected PHP CLI binary is missing' && exit 1
fi

sudo chown root:"$VITO_SITE_USER" "$VITO_RUNTIME_PATH/99-vito-site.ini" "$VITO_PROFILE"
sudo chmod 0640 "$VITO_RUNTIME_PATH/99-vito-site.ini" "$VITO_PROFILE"
@if ($sharedUser)
sudo chown "$VITO_SITE_USER:$VITO_SITE_USER" "$VITO_HOME/bin/php"
sudo chmod 0750 "$VITO_HOME/bin/php"
for VITO_SHELL_FILE in "$VITO_HOME/.profile" "$VITO_HOME/.bashrc"; do
    if sudo test -f "$VITO_SHELL_FILE"; then
        sudo sed -i '\|/var/lib/vito/php-cli/vito-site-.*/profile|d' "$VITO_SHELL_FILE"
    fi
done
@else
sudo ln -sfn "$VITO_PHP_BINARY" "$VITO_HOME/bin/php.next"
sudo mv -Tf -- "$VITO_HOME/bin/php.next" "$VITO_HOME/bin/php"
sudo chown -h "$VITO_SITE_USER:$VITO_SITE_USER" "$VITO_HOME/bin/php"

for VITO_SHELL_FILE in "$VITO_HOME/.profile" "$VITO_HOME/.bashrc"; do
    sudo touch "$VITO_SHELL_FILE"
    if ! sudo grep -qxF "$VITO_PROFILE_LINE" "$VITO_SHELL_FILE"; then
        printf '%s\n' "$VITO_PROFILE_LINE" | sudo tee -a "$VITO_SHELL_FILE" >/dev/null
    fi
    sudo chown "$VITO_SITE_USER:$VITO_SITE_USER" "$VITO_SHELL_FILE"
done
@endif

if ! sudo -u "$VITO_SITE_USER" env PHP_INI_SCAN_DIR="$VITO_INI_SCAN_DIR" "$VITO_PHP_BINARY" -r 'exit(PHP_MAJOR_VERSION > 0 ? 0 : 1);'; then
    echo 'VITO_SSH_ERROR: site PHP CLI validation failed' && exit 1
fi

unset VITO_SITE_USER VITO_HOME VITO_PHP_BINARY VITO_RUNTIME_PATH VITO_PROFILE VITO_INI_SCAN_DIR VITO_PROFILE_LINE VITO_SHELL_FILE
