VITO_SITE_USER={!! escapeshellarg($siteUser) !!}
VITO_NGINX_USER={!! escapeshellarg($workerUser) !!}
VITO_SITE_PATH={!! escapeshellarg($sitePath) !!}
VITO_WEB_ROOT={!! escapeshellarg($webRoot) !!}
VITO_TEMP_PATH={!! escapeshellarg($temporaryPath) !!}
VITO_LOG_PATH={!! escapeshellarg($logDirectory) !!}
VITO_STATE_PATH={!! escapeshellarg($stateDirectory) !!}

if ! id -u "$VITO_NGINX_USER" >/dev/null 2>&1; then
    echo 'VITO_SSH_ERROR: nginx identity is missing' && exit 1
fi

@if ($isIsolated)
if ! id -u "$VITO_SITE_USER" >/dev/null 2>&1; then
    echo 'VITO_SSH_ERROR: site identity is missing' && exit 1
fi
if getent group "$VITO_SITE_USER" >/dev/null 2>&1 && id -nG "$VITO_NGINX_USER" | tr ' ' '\n' | grep -qx "$VITO_SITE_USER"; then
    sudo gpasswd -d "$VITO_NGINX_USER" "$VITO_SITE_USER" >/dev/null 2>&1 || true
fi
sudo install -d -o "$VITO_SITE_USER" -g "$VITO_SITE_USER" -m 0750 "$VITO_SITE_PATH"
sudo install -d -o "$VITO_SITE_USER" -g "$VITO_SITE_USER" -m 0700 "$VITO_TEMP_PATH"
@if ($createWebRoot)
sudo install -d -o "$VITO_SITE_USER" -g "$VITO_SITE_USER" -m 0750 "$VITO_WEB_ROOT"
@endif
sudo setfacl -m u:"$VITO_NGINX_USER":--x "/home/$VITO_SITE_USER" "$VITO_SITE_PATH"
if sudo test -d "$VITO_WEB_ROOT"; then
    sudo find "$VITO_WEB_ROOT" -type d -exec setfacl -m u:"$VITO_NGINX_USER":r-x,d:u:"$VITO_NGINX_USER":r-x {} +
    sudo find "$VITO_WEB_ROOT" -type f -exec setfacl -m u:"$VITO_NGINX_USER":r-- {} +
fi
@endif
sudo install -d -o "$VITO_NGINX_USER" -g "$VITO_NGINX_USER" -m 0750 "$VITO_LOG_PATH"
sudo install -d -o root -g root -m 0700 "$VITO_STATE_PATH"
sudo touch "$VITO_LOG_PATH/access.log" "$VITO_LOG_PATH/error.log"
sudo chown "$VITO_NGINX_USER:$VITO_NGINX_USER" "$VITO_LOG_PATH/access.log" "$VITO_LOG_PATH/error.log"
sudo chmod 0640 "$VITO_LOG_PATH/access.log" "$VITO_LOG_PATH/error.log"

unset VITO_SITE_USER VITO_NGINX_USER VITO_SITE_PATH VITO_WEB_ROOT VITO_TEMP_PATH VITO_LOG_PATH VITO_STATE_PATH
