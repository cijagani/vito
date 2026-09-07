if [ "{{ $isIsolated }}" == "true" ]; then
    cd {!! escapeshellarg($homeDirectory) !!}
else
    cd /tmp
fi

if ! curl -sS -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

if ! chmod +x wp-cli.phar; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

if [ "{{ $isIsolated }}" == "true" ]; then
    mv wp-cli.phar {!! escapeshellarg($homeDirectory.'/bin/') !!}
    ln -sf {!! escapeshellarg($homeDirectory.'/bin/wp-cli.phar') !!} {!! escapeshellarg($homeDirectory.'/bin/wp') !!}
else
    if ! sudo mv wp-cli.phar /usr/local/bin/wp; then
        echo 'VITO_SSH_ERROR' && exit 1
    fi
fi

rm -rf {{ $path }}

if ! wp --path={{ $path }} core download; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

if ! wp --path={{ $path }} core config \
    --dbname="{{ $dbName }}" \
    --dbuser="{{ $dbUser }}" \
    --dbpass="{{ $dbPass }}" \
    --dbhost="{{ $dbHost }}" \
    --dbprefix="{{ $dbPrefix }}"; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

if ! wp --path={{ $path }} core install \
    --url="http://{{ $domain }}" \
    --title="{{ $title }}" \
    --admin_user="{{ $username }}" \
    --admin_password="{{ $password }}" \
    --admin_email="{{ $email }}"; then
    echo 'VITO_SSH_ERROR' && exit 1
fi
