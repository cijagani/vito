VITO_USER={!! escapeshellarg($user) !!}
VITO_SERVER_USER={!! escapeshellarg($serverUser) !!}
VITO_WEBSERVER_USER={!! escapeshellarg($webserverUser) !!}
sudo gpasswd -d "$VITO_SERVER_USER" "$VITO_USER" >/dev/null 2>&1 || true
sudo gpasswd -d "$VITO_WEBSERVER_USER" "$VITO_USER" >/dev/null 2>&1 || true
sudo rm -f {!! escapeshellarg('/var/lib/vito/managed-users/'.$user) !!}
sudo userdel -r -f "$VITO_USER"
sudo groupdel "$VITO_USER" >/dev/null 2>&1 || true
echo "User $VITO_USER has been deleted."
unset VITO_USER VITO_SERVER_USER VITO_WEBSERVER_USER
