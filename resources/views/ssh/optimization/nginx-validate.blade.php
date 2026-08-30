{{--
    Asks nginx to parse its configuration before it is told to use it.

    -t reads every included file and fails on a duplicate directive or a syntax
    error, so a broken configuration is caught while it can still be put back.
--}}
if sudo nginx -t > /dev/null 2>&1; then
    echo 'VITO_CONFIG_OK'
else
    echo 'VITO_SSH_ERROR: nginx rejected the configuration' >&2
    exit 1
fi
