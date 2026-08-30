{{--
    Asks the server to parse its configuration without starting or restarting it.

    --validate-config exists on MySQL 8.0+ and reports a non-zero exit for an
    unknown or malformed option. MariaDB has no equivalent, so it falls back to
    --help --verbose, which also reads every config file and fails the same way on
    a bad option.
--}}
if sudo mysqld --validate-config > /dev/null 2>&1; then
    echo 'VITO_CONFIG_OK'
elif sudo mysqld --help --verbose > /dev/null 2>&1; then
    echo 'VITO_CONFIG_OK'
else
    echo 'VITO_SSH_ERROR: the database rejected the configuration' >&2
    exit 1
fi
