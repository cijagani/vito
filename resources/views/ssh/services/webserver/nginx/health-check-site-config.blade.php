VITO_DOMAIN={!! escapeshellarg($domain) !!}
VITO_SITE_ID={!! escapeshellarg((string) $siteId) !!}

if ! curl --silent --show-error --output /dev/null --dump-header - --connect-timeout 2 --max-time 5 --header "Host: $VITO_DOMAIN" http://127.0.0.1/ \
    | tr -d '\r' \
    | grep -i -x -F "X-Vito-Site-ID: $VITO_SITE_ID" >/dev/null; then
    echo 'VITO_SSH_ERROR: nginx did not route the site hostname' && exit 1
fi

unset VITO_DOMAIN VITO_SITE_ID
