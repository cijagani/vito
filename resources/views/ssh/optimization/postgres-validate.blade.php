{{--
    Asks PostgreSQL to parse its configuration without restarting the server.

    `postgres -C` reads the configuration and prints one setting, exiting non-zero
    if the files do not parse. Running it as the postgres user avoids permission
    noise on the data directory.
--}}
if sudo -u postgres /usr/lib/postgresql/{{ $version }}/bin/postgres \
    -C shared_buffers \
    -D /var/lib/postgresql/{{ $version }}/main >/dev/null 2>&1; then
    echo 'VITO_CONFIG_OK'
else
    echo 'VITO_SSH_ERROR: postgresql rejected the configuration' >&2
    exit 1
fi
