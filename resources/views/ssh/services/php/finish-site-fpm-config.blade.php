VITO_CANDIDATE={!! escapeshellarg($candidatePath) !!}
VITO_SERVICE_CANDIDATE={!! escapeshellarg($serviceCandidatePath) !!}
VITO_SLICE_CANDIDATE={!! escapeshellarg($sliceCandidatePath) !!}
VITO_PREVIOUS={!! escapeshellarg($stateDirectory.'/previous') !!}
VITO_VALIDATION={!! escapeshellarg($stateDirectory.'/validation') !!}

sudo rm -f -- "$VITO_CANDIDATE"
sudo rm -f -- "$VITO_SERVICE_CANDIDATE" "$VITO_SLICE_CANDIDATE"
sudo rm -rf -- "$VITO_PREVIOUS" "$VITO_VALIDATION"

unset VITO_CANDIDATE VITO_SERVICE_CANDIDATE VITO_SLICE_CANDIDATE VITO_PREVIOUS VITO_VALIDATION
