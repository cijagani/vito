VITO_NGINX_USER={!! escapeshellarg($workerUser) !!}
VITO_CONTROL_USER={!! escapeshellarg($controlUser) !!}
VITO_LEGACY_SOCKET_GROUP={!! escapeshellarg($legacySocketGroup) !!}
VITO_NGINX_CONF='/etc/nginx/nginx.conf'
VITO_NGINX_BACKUP='/etc/nginx/nginx.conf.vito-user-backup'
VITO_ISOLATION_CONF='/etc/nginx/conf.d/vito-site-isolation.conf'
VITO_ISOLATION_BACKUP='/etc/nginx/conf.d/vito-site-isolation.conf.vito-backup'
VITO_NGINX_CHANGED=0
VITO_ISOLATION_EXISTED=0

if ! id -u "$VITO_NGINX_USER" >/dev/null 2>&1; then
    sudo useradd --system --no-create-home --shell /usr/sbin/nologin "$VITO_NGINX_USER"
fi

if ! command -v setfacl >/dev/null 2>&1; then
    sudo env DEBIAN_FRONTEND=noninteractive apt-get update -y
    sudo env DEBIAN_FRONTEND=noninteractive apt-get install -y acl
fi

sudo install -d -o root -g root -m 0700 /var/lib/vito/nginx

if getent group "$VITO_LEGACY_SOCKET_GROUP" >/dev/null 2>&1 && ! id -nG "$VITO_NGINX_USER" | tr ' ' '\n' | grep -qx "$VITO_LEGACY_SOCKET_GROUP"; then
    sudo usermod -a -G "$VITO_LEGACY_SOCKET_GROUP" "$VITO_NGINX_USER"
fi

@foreach ($siteUsers as $siteUser)
if getent group {!! escapeshellarg($siteUser) !!} >/dev/null 2>&1 && id -nG "$VITO_NGINX_USER" | tr ' ' '\n' | grep -qx {!! escapeshellarg($siteUser) !!}; then
    if ! sudo gpasswd -d "$VITO_NGINX_USER" {!! escapeshellarg($siteUser) !!} >/dev/null 2>&1; then
        echo 'VITO_SSH_ERROR: nginx identity could not be removed from a site group' && exit 1
    fi
    VITO_NGINX_CHANGED=1
fi
if [ "$VITO_CONTROL_USER" != 'root' ] && [ "$VITO_CONTROL_USER" != {!! escapeshellarg($siteUser) !!} ]; then
    sudo gpasswd -d "$VITO_CONTROL_USER" {!! escapeshellarg($siteUser) !!} >/dev/null 2>&1 || true
fi
@endforeach

if ! sudo grep -qE '^[[:space:]]*user[[:space:]]+' "$VITO_NGINX_CONF"; then
    sudo cp -p "$VITO_NGINX_CONF" "$VITO_NGINX_BACKUP"
    sudo sh -c 'printf "user %s;\n" "$1" > "$2"; cat "$3" >> "$2"' sh "$VITO_NGINX_USER" "$VITO_NGINX_CONF.vito-next" "$VITO_NGINX_CONF"
    sudo mv "$VITO_NGINX_CONF.vito-next" "$VITO_NGINX_CONF"
    VITO_NGINX_CHANGED=1
elif ! sudo grep -qE "^[[:space:]]*user[[:space:]]+$VITO_NGINX_USER;" "$VITO_NGINX_CONF"; then
    sudo cp -p "$VITO_NGINX_CONF" "$VITO_NGINX_BACKUP"
    sudo sed -i -E "s/^[[:space:]]*user[[:space:]]+[^;]+;/user $VITO_NGINX_USER;/" "$VITO_NGINX_CONF"
    VITO_NGINX_CHANGED=1
fi

if sudo test -e "$VITO_ISOLATION_CONF"; then
    VITO_ISOLATION_EXISTED=1
fi
if ! sudo test -f "$VITO_ISOLATION_CONF" || ! sudo grep -qxF 'disable_symlinks if_not_owner from=$document_root;' "$VITO_ISOLATION_CONF"; then
    if [ "$VITO_ISOLATION_EXISTED" = '1' ]; then
        sudo cp -p "$VITO_ISOLATION_CONF" "$VITO_ISOLATION_BACKUP"
    fi
    printf '%s\n' 'disable_symlinks if_not_owner from=$document_root;' | sudo tee "$VITO_ISOLATION_CONF" >/dev/null
    sudo chown root:root "$VITO_ISOLATION_CONF"
    sudo chmod 0644 "$VITO_ISOLATION_CONF"
    VITO_NGINX_CHANGED=1
fi

if ! sudo grep -qE "^[[:space:]]*user[[:space:]]+$VITO_NGINX_USER;" "$VITO_NGINX_CONF" || ! sudo nginx -t; then
    if sudo test -f "$VITO_NGINX_BACKUP"; then
        sudo mv "$VITO_NGINX_BACKUP" "$VITO_NGINX_CONF"
    fi
    if [ "$VITO_ISOLATION_EXISTED" = '1' ] && sudo test -f "$VITO_ISOLATION_BACKUP"; then
        sudo mv "$VITO_ISOLATION_BACKUP" "$VITO_ISOLATION_CONF"
    elif [ "$VITO_ISOLATION_EXISTED" = '0' ]; then
        sudo rm -f "$VITO_ISOLATION_CONF"
    fi
    echo 'VITO_SSH_ERROR: nginx runtime identity configuration is invalid' && exit 1
fi

if [ "$VITO_NGINX_CHANGED" = '1' ] && ! sudo systemctl reload nginx; then
    if sudo test -f "$VITO_NGINX_BACKUP"; then
        sudo mv "$VITO_NGINX_BACKUP" "$VITO_NGINX_CONF"
    fi
    if [ "$VITO_ISOLATION_EXISTED" = '1' ] && sudo test -f "$VITO_ISOLATION_BACKUP"; then
        sudo mv "$VITO_ISOLATION_BACKUP" "$VITO_ISOLATION_CONF"
    elif [ "$VITO_ISOLATION_EXISTED" = '0' ]; then
        sudo rm -f "$VITO_ISOLATION_CONF"
    fi
    sudo nginx -t && sudo systemctl reload nginx || true
    echo 'VITO_SSH_ERROR: nginx runtime identity could not be activated' && exit 1
fi

sudo rm -f "$VITO_NGINX_BACKUP" "$VITO_ISOLATION_BACKUP" || true
unset VITO_NGINX_USER VITO_CONTROL_USER VITO_LEGACY_SOCKET_GROUP VITO_NGINX_CONF VITO_NGINX_BACKUP VITO_ISOLATION_CONF VITO_ISOLATION_BACKUP VITO_NGINX_CHANGED VITO_ISOLATION_EXISTED
