VITO_SOCKET={!! escapeshellarg($socketPath) !!}
VITO_WEBSERVER_USER={!! escapeshellarg($webserverUser) !!}
VITO_ATTEMPTS=0

while ! sudo test -S "$VITO_SOCKET"; do
    VITO_ATTEMPTS=$((VITO_ATTEMPTS + 1))
    if [ "$VITO_ATTEMPTS" -ge 20 ]; then
        echo 'VITO_SSH_ERROR: PHP-FPM site socket was not created' && exit 1
    fi
    sleep 0.25
done

VITO_OWNER=$(sudo stat -c '%U:%G' "$VITO_SOCKET")
if [ "$VITO_OWNER" != "$VITO_WEBSERVER_USER:$VITO_WEBSERVER_USER" ]; then
    echo 'VITO_SSH_ERROR: PHP-FPM site socket has an unexpected owner' && exit 1
fi

unset VITO_SOCKET VITO_WEBSERVER_USER VITO_ATTEMPTS VITO_OWNER
