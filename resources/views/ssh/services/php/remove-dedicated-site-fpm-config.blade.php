VITO_TARGET={!! escapeshellarg($targetPath) !!}
VITO_SOCKET={!! escapeshellarg($socketPath) !!}
VITO_STATE={!! escapeshellarg($stateDirectory) !!}
VITO_CLI_RUNTIME={!! escapeshellarg($cliRuntimeDirectory) !!}
VITO_SITE_PHP_LINK={!! escapeshellarg($sitePhpLink) !!}
VITO_SERVICE={!! escapeshellarg($serviceUnit) !!}
VITO_SERVICE_PATH={!! escapeshellarg($servicePath) !!}
VITO_SLICE_PATH={!! escapeshellarg($slicePath) !!}

sudo systemctl disable --now "$VITO_SERVICE" || true
sudo rm -f -- "$VITO_TARGET" "$VITO_SOCKET" "$VITO_SERVICE_PATH" "$VITO_SLICE_PATH"
sudo systemctl daemon-reload || true
sudo rm -rf -- "$VITO_STATE"
@if ($removeCliRuntime)
sudo rm -rf -- "$VITO_CLI_RUNTIME"
@endif
@if ($removeSitePhpLink)
sudo rm -f -- "$VITO_SITE_PHP_LINK"
@endif

unset VITO_TARGET VITO_SOCKET VITO_STATE VITO_CLI_RUNTIME VITO_SITE_PHP_LINK VITO_SERVICE VITO_SERVICE_PATH VITO_SLICE_PATH
