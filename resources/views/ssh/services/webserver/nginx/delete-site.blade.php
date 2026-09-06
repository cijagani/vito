VITO_SITE_PATH={!! escapeshellarg($path) !!}

case "$VITO_SITE_PATH" in
    /home/*/*) ;;
    *) echo 'VITO_SSH_ERROR: refusing to delete an unsafe site path' && exit 1 ;;
esac
case "$VITO_SITE_PATH" in
    *'/../'*|*/..|*'/./'*|*/.|*'//'* ) echo 'VITO_SSH_ERROR: refusing to delete an unsafe site path' && exit 1 ;;
esac

sudo rm -rf -- "$VITO_SITE_PATH"
sudo rm -f -- \
    {!! escapeshellarg($targetPath) !!} \
    {!! escapeshellarg($enabledPath) !!} \
    {!! escapeshellarg($legacyTargetPath) !!} \
    {!! escapeshellarg($legacyEnabledPath) !!}
sudo rm -rf -- {!! escapeshellarg($logDirectory) !!} {!! escapeshellarg($stateDirectory) !!}

echo "Site deleted"
unset VITO_SITE_PATH
