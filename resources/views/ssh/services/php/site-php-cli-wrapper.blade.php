#!/bin/sh

VITO_WORKING_DIRECTORY=$(pwd -P)

case "$VITO_WORKING_DIRECTORY/" in
@foreach ($runtimeSites as $runtimeSite)
    {!! escapeshellarg(rtrim((string) $runtimeSite->path, '/').'/') !!}*)
        export VITO_SITE_ID={!! escapeshellarg((string) $runtimeSite->id) !!}
        export PHP_VERSION={!! escapeshellarg((string) $runtimeSite->php_version) !!}
        export PHP_BINARY={!! escapeshellarg('/usr/bin/php'.$runtimeSite->php_version) !!}
        export PHP_PATH="$PHP_BINARY"
        export PHP_INI_SCAN_DIR={!! escapeshellarg('/etc/php/'.$runtimeSite->php_version.'/cli/conf.d:'.$runtimeSite->runtimeArtifacts()->phpCliIniDirectory()) !!}
        export TMPDIR={!! escapeshellarg('/home/'.$runtimeSite->user.'/tmp/'.$runtimeSite->runtimeArtifacts()->key()) !!}
        ;;
@endforeach
    *)
        export PHP_BINARY={!! escapeshellarg($defaultPhpBinary) !!}
        export PHP_PATH="$PHP_BINARY"
        export PHP_INI_SCAN_DIR={!! escapeshellarg($defaultIniScanDirectory) !!}
        ;;
esac

unset VITO_WORKING_DIRECTORY
exec "$PHP_BINARY" "$@"
