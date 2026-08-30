{{--
    Asks PHP-FPM to parse its configuration without restarting the running pools.

    `-t` reads every pool file and the ini directories, exiting non-zero when any
    of them is invalid, so a broken pool is caught before the service is reloaded
    rather than at the next restart.
--}}
if sudo php-fpm{{ $version }} -t > /dev/null 2>&1; then
    echo 'VITO_CONFIG_OK'
else
    echo 'VITO_SSH_ERROR: php-fpm rejected the configuration' >&2
    exit 1
fi
