{{--
    Reports which file already declares each directive, or "none".

    nginx refuses a directive declared twice in the same context, so a value has
    to be changed where it already lives rather than restated. Our own managed
    file is excluded from the search: finding our previous write would make every
    directive look as though it belongs to us.
--}}
sudo sh -c 'for d in {{ $directives }}; do
    f=$(grep -rlsE "^[[:space:]]*${d}[[:space:]]" /etc/nginx/nginx.conf /etc/nginx/conf.d/*.conf 2>/dev/null | grep -v "zz-vito-tuning" | head -n1)
    echo "${d}:${f:-none}"
done'
