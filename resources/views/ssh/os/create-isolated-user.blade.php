export DEBIAN_FRONTEND=noninteractive
VITO_USER={!! escapeshellarg($user) !!}
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
    if ! sudo useradd --create-home --home-dir "/home/$VITO_USER" --shell /bin/bash --user-group \
        --password "$(openssl passwd -6 {!! escapeshellarg($password) !!})" "$VITO_USER"; then
        echo 'VITO_SSH_ERROR' && exit 1
    fi
    VITO_USER_CREATED=1
fi

sudo mkdir -p "/home/$VITO_USER" "/home/$VITO_USER/.logs" "/home/$VITO_USER/tmp" "/home/$VITO_USER/bin" "/home/$VITO_USER/.ssh"
sudo mkdir -p "$(dirname "$VITO_MARKER")"
sudo touch "$VITO_MARKER"
sudo chown root:root "$VITO_MARKER"
sudo chmod 600 "$VITO_MARKER"
sudo touch "/home/$VITO_USER/.ssh/authorized_keys"
VITO_PUBKEY={!! $key !!}
if ! sudo grep -qxF "$VITO_PUBKEY" "/home/$VITO_USER/.ssh/authorized_keys"; then
    printf '%s\n' "$VITO_PUBKEY" | sudo tee -a "/home/$VITO_USER/.ssh/authorized_keys"
fi
unset VITO_PUBKEY
PATH_LINE="export PATH=\"/home/$VITO_USER/bin:\$PATH\""
UMASK_LINE='umask 027'
sudo touch "/home/$VITO_USER/.bashrc" "/home/$VITO_USER/.profile"
sudo grep -qxF "$PATH_LINE" "/home/$VITO_USER/.bashrc" || echo "$PATH_LINE" | sudo tee -a "/home/$VITO_USER/.bashrc"
sudo grep -qxF "$PATH_LINE" "/home/$VITO_USER/.profile" || echo "$PATH_LINE" | sudo tee -a "/home/$VITO_USER/.profile"
sudo grep -qxF "$UMASK_LINE" "/home/$VITO_USER/.bashrc" || echo "$UMASK_LINE" | sudo tee -a "/home/$VITO_USER/.bashrc"
sudo grep -qxF "$UMASK_LINE" "/home/$VITO_USER/.profile" || echo "$UMASK_LINE" | sudo tee -a "/home/$VITO_USER/.profile"
@if ($grantWebserverGroup)
if ! id -nG "$VITO_WEBSERVER_USER" | tr ' ' '\n' | grep -qx "$VITO_USER"; then
    sudo usermod -a -G "$VITO_USER" "$VITO_WEBSERVER_USER"
fi
@endif
sudo chown -R "$VITO_USER:$VITO_USER" "/home/$VITO_USER"
sudo chmod 750 "/home/$VITO_USER"
sudo chmod 700 "/home/$VITO_USER/.ssh"
sudo chmod 600 "/home/$VITO_USER/.ssh/authorized_keys"
sudo chmod 700 "/home/$VITO_USER/.logs" "/home/$VITO_USER/tmp"
sudo chsh -s /bin/bash "$VITO_USER"
if [ "$VITO_USER_CREATED" = "1" ]; then
    echo "Created user $VITO_USER."
fi
unset VITO_USER_CREATED VITO_USER VITO_WEBSERVER_USER VITO_MARKER
