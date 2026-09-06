VITO_STATE_PATH={!! escapeshellarg($stateDirectory) !!}

sudo install -d -o root -g root -m 0700 "$VITO_STATE_PATH"

unset VITO_STATE_PATH
