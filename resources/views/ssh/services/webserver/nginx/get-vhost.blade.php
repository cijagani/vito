if sudo test -f {!! escapeshellarg($targetPath) !!}; then
    sudo cat {!! escapeshellarg($targetPath) !!}
else
    sudo cat {!! escapeshellarg($legacyPath) !!}
fi
