VITO_SITE_USER={!! escapeshellarg($siteUser) !!}
VITO_WEBSERVER_USER={!! escapeshellarg($webserverUser) !!}
VITO_TEMP_PATH={!! escapeshellarg($temporaryPath) !!}
VITO_LOG_PATH={!! escapeshellarg($logDirectory) !!}
VITO_STATE_PATH={!! escapeshellarg($stateDirectory) !!}

if ! id -u "$VITO_SITE_USER" >/dev/null 2>&1; then
    echo 'VITO_SSH_ERROR: site identity is missing' && exit 1
fi

if ! command -v setfacl >/dev/null 2>&1; then
    sudo apt-get update -y >/dev/null && sudo apt-get install -y acl >/dev/null
fi

sudo install -d -o "$VITO_SITE_USER" -g "$VITO_SITE_USER" -m 0700 "$VITO_TEMP_PATH"
sudo mkdir -p -- "$VITO_LOG_PATH"
sudo chmod 0750 "$VITO_LOG_PATH"
sudo setfacl -m u:"$VITO_SITE_USER":rwx,u:"$VITO_WEBSERVER_USER":rwx "$VITO_LOG_PATH"
sudo install -d -o root -g root -m 0700 "$VITO_STATE_PATH"
sudo touch "$VITO_LOG_PATH/php-error.log" "$VITO_LOG_PATH/php-slow.log"
sudo chown "$VITO_SITE_USER:$VITO_SITE_USER" "$VITO_LOG_PATH/php-error.log" "$VITO_LOG_PATH/php-slow.log"
sudo chmod 0640 "$VITO_LOG_PATH/php-error.log" "$VITO_LOG_PATH/php-slow.log"

unset VITO_SITE_USER VITO_WEBSERVER_USER VITO_TEMP_PATH VITO_LOG_PATH VITO_STATE_PATH
