export DEBIAN_FRONTEND=noninteractive
VITO_USER={!! escapeshellarg($user) !!}
VITO_HOME={!! escapeshellarg($homeDirectory) !!}
VITO_WEBSERVER_USER={!! escapeshellarg($webserverUser) !!}
VITO_MARKER={!! escapeshellarg('/var/lib/vito/managed-users/'.$user) !!}

if ! id -u "$VITO_WEBSERVER_USER" >/dev/null 2>&1; then
    sudo useradd --system --no-create-home --shell /usr/sbin/nologin "$VITO_WEBSERVER_USER"
fi

if id -u "$VITO_USER" >/dev/null 2>&1; then
    if ! sudo test -f "$VITO_MARKER" && [ {!! $allowExisting ? '0' : '1' !!} -eq 1 ]; then
        echo 'VITO_SSH_ERROR: unmanaged Linux account already exists' && exit 1
    fi
    echo "User $VITO_USER already exists, reasserting directories and permissions."
    VITO_USER_CREATED=0
else
    if ! sudo useradd --create-home --home-dir "$VITO_HOME" --shell /bin/bash --user-group \
        --password "$(openssl passwd -6 {!! escapeshellarg($password) !!})" "$VITO_USER"; then
        echo 'VITO_SSH_ERROR' && exit 1
    fi
    VITO_USER_CREATED=1
fi

sudo mkdir -p "$VITO_HOME" "$VITO_HOME/.logs" "$VITO_HOME/tmp" "$VITO_HOME/bin" "$VITO_HOME/.ssh"
sudo mkdir -p "$(dirname "$VITO_MARKER")"
sudo touch "$VITO_MARKER"
sudo chown root:root "$VITO_MARKER"
sudo chmod 600 "$VITO_MARKER"
sudo touch "$VITO_HOME/.ssh/authorized_keys"
VITO_PUBKEY={!! $key !!}
if ! sudo grep -qxF "$VITO_PUBKEY" "$VITO_HOME/.ssh/authorized_keys"; then
    printf '%s\n' "$VITO_PUBKEY" | sudo tee -a "$VITO_HOME/.ssh/authorized_keys"
fi
unset VITO_PUBKEY
PATH_LINE="export PATH=\"$VITO_HOME/bin:\$PATH\""
UMASK_LINE='umask 027'
sudo touch "$VITO_HOME/.bashrc" "$VITO_HOME/.profile"
sudo grep -qxF "$PATH_LINE" "$VITO_HOME/.bashrc" || echo "$PATH_LINE" | sudo tee -a "$VITO_HOME/.bashrc"
sudo grep -qxF "$PATH_LINE" "$VITO_HOME/.profile" || echo "$PATH_LINE" | sudo tee -a "$VITO_HOME/.profile"
sudo grep -qxF "$UMASK_LINE" "$VITO_HOME/.bashrc" || echo "$UMASK_LINE" | sudo tee -a "$VITO_HOME/.bashrc"
sudo grep -qxF "$UMASK_LINE" "$VITO_HOME/.profile" || echo "$UMASK_LINE" | sudo tee -a "$VITO_HOME/.profile"
@if ($grantWebserverGroup)
if ! id -nG "$VITO_WEBSERVER_USER" | tr ' ' '\n' | grep -qx "$VITO_USER"; then
    sudo usermod -a -G "$VITO_USER" "$VITO_WEBSERVER_USER"
fi
@endif
sudo chown -R "$VITO_USER:$VITO_USER" "$VITO_HOME"
sudo chmod 750 "$VITO_HOME"
sudo chmod 700 "$VITO_HOME/.ssh"
sudo chmod 600 "$VITO_HOME/.ssh/authorized_keys"
sudo chmod 700 "$VITO_HOME/.logs" "$VITO_HOME/tmp"
sudo chsh -s /bin/bash "$VITO_USER"
if [ "$VITO_USER_CREATED" = "1" ]; then
    echo "Created user $VITO_USER."
fi
unset VITO_USER_CREATED VITO_USER VITO_HOME VITO_WEBSERVER_USER VITO_MARKER
