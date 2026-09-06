VITO_CANDIDATE={!! escapeshellarg($candidatePath) !!}
VITO_PREVIOUS={!! escapeshellarg($stateDirectory.'/previous') !!}
VITO_VALIDATION={!! escapeshellarg($stateDirectory.'/validation') !!}

sudo rm -f -- "$VITO_CANDIDATE"
sudo rm -rf -- "$VITO_PREVIOUS" "$VITO_VALIDATION"

unset VITO_CANDIDATE VITO_PREVIOUS VITO_VALIDATION
