{{--
    Sets one directive on the running Redis server.

    CONFIG SET applies the value immediately and reports an error rather than a
    silent failure when the value is out of range or the directive is not
    settable at runtime, which is what makes it usable as a validity check.
--}}
if redis-cli CONFIG SET {{ $key }} {{ $value }} 2>/dev/null | grep -q '^OK$'; then
    echo 'VITO_CONFIG_OK'
else
    echo 'VITO_SSH_ERROR: redis rejected {{ $key }}' >&2
    exit 1
fi
