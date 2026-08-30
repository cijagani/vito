{{--
    Reads the facts the optimizer cannot get from Vito's own database: live
    hardware detail and the config values currently in force.

    One round trip, one key:value line per fact, mirroring ssh/os/resource-info
    so App\Actions\Optimization\Probe can parse both the same way. Every lookup
    is guarded so a missing service leaves an empty value rather than failing the
    whole probe -- an unset key means "not installed", not "the probe broke".
--}}
echo "total_ram_mb:$(free -m | awk 'NR==2{print $2}')"
echo "swap_total_mb:$(free -m | awk 'NR==3{print $2}')"
echo "cores:$(nproc 2>/dev/null || echo 1)"
echo "physical_cores:$(lscpu -p=Socket,Core 2>/dev/null | grep -v '^#' | sort -u | wc -l)"

{{-- The root device backing /, resolved through any device-mapper layers, then
     asked whether it actually rotates. Drives random_page_cost and
     effective_io_concurrency, where guessing costs real query time. --}}
echo "disk_rotational:$(
    root_src=$(findmnt -no SOURCE / 2>/dev/null | sed 's/\[.*\]//')
    root_dev=$(lsblk -no PKNAME "$root_src" 2>/dev/null | head -n1)
    [ -z "$root_dev" ] && root_dev=$(basename "$root_src" | sed 's/[0-9]*$//')
    cat "/sys/block/${root_dev}/queue/rotational" 2>/dev/null || echo 0
)"

{{-- Many sysctl keys are not settable inside a container; phases that write them
     must skip rather than fail confusingly. --}}
echo "virtualisation:$(systemd-detect-virt 2>/dev/null || echo none)"

echo "nofile_limit:$(ulimit -Hn 2>/dev/null || echo '')"
echo "php_versions:$(ls /etc/php 2>/dev/null | tr '\n' ',' | sed 's/,$//')"

{{-- The measured average resident size of a live FPM worker. This is the divisor
     that turns the pool budget into a worker count, so a guess here causes either
     OOM or wasted capacity. Empty when no pool is running, and the budget then
     falls back to a conservative default. --}}
echo "fpm_avg_rss_mb:$(
    ps -o rss= -C php-fpm8.4 -C php-fpm8.3 -C php-fpm8.2 -C php-fpm8.1 2>/dev/null \
        | awk '{ sum += $1; n++ } END { if (n > 0) printf "%d", (sum / n) / 1024 }'
)"
echo "fpm_active_children:$(pgrep -c 'php-fpm' 2>/dev/null || echo 0)"

{{-- The newest installed version, whose pool serves requests. Reading across
     every version mixes their settings and then reports whichever the glob
     happened to list last. --}}
DEFAULT_PHP=$(ls -1 /etc/php 2>/dev/null | sort -V | tail -n1)

{{-- OPcache as the FPM pool will load it. php -i under the fpm SAPI is not
     available, so the value is read from the ini files that SAPI reads -- the CLI
     has its own conf.d and would report a different number entirely.

     conf.d is loaded in sorted order with the last definition winning, so the
     files are sorted here the same way rather than trusting the glob. --}}
@foreach (['opcache.memory_consumption', 'opcache.max_accelerated_files', 'opcache.interned_strings_buffer', 'opcache.jit_buffer_size'] as $ini)
echo "php_{{ preg_replace('/[^a-z0-9]+/i', '_', $ini) }}:$(sudo sh -c "grep -shE '^[[:space:]]*{{ $ini }}[[:space:]]*=' /etc/php/$DEFAULT_PHP/fpm/php.ini \$(ls -1 /etc/php/$DEFAULT_PHP/fpm/conf.d/*.ini 2>/dev/null | sort)" 2>/dev/null | tail -n1 | sed -E 's/.*=[[:space:]]*//')"
@endforeach

@if ($postgresVersion)
{{-- Asked of the running server rather than read from the file, so the value
     reflects what is actually in force including any drop-in overrides. --}}
echo "pg_version:$(sudo -u postgres psql -tAc 'SHOW server_version' 2>/dev/null | awk '{print $1}' || echo '')"
echo "pg_shared_buffers:$(sudo -u postgres psql -tAc 'SHOW shared_buffers' 2>/dev/null || echo '')"
echo "pg_work_mem:$(sudo -u postgres psql -tAc 'SHOW work_mem' 2>/dev/null || echo '')"
echo "pg_max_connections:$(sudo -u postgres psql -tAc 'SHOW max_connections' 2>/dev/null || echo '')"
echo "pg_effective_cache_size:$(sudo -u postgres psql -tAc 'SHOW effective_cache_size' 2>/dev/null || echo '')"
echo "pg_random_page_cost:$(sudo -u postgres psql -tAc 'SHOW random_page_cost' 2>/dev/null || echo '')"
echo "pg_data_directory:$(sudo -u postgres psql -tAc 'SHOW data_directory' 2>/dev/null || echo '')"
@endif

@if ($mysqlVersion)
{{-- The full version string, since MariaDB and Percona identify themselves there
     and the settings they support differ from standard MySQL. --}}
echo "mysql_version:$(sudo mysql -N -B -e 'SELECT VERSION()' 2>/dev/null || echo '')"
@foreach (['innodb_buffer_pool_size', 'innodb_flush_method', 'innodb_log_file_size', 'innodb_log_buffer_size', 'innodb_io_capacity', 'innodb_io_capacity_max', 'innodb_file_per_table', 'max_connections', 'thread_cache_size', 'table_open_cache', 'skip_name_resolve', 'slow_query_log', 'long_query_time'] as $variable)
{{-- SHOW VARIABLES rather than a @@session variable: Blade strips a leading @ and
     treats @{ as its own escape, so the sigil cannot survive interpolation
     reliably however it is written. --}}
echo "mysql_{{ $variable }}:$(sudo mysql -N -B -e "SHOW VARIABLES LIKE '{{ $variable }}'" 2>/dev/null | awk '{print $2}' || echo '')"
@endforeach
@endif

@if ($redisInstalled)
{{-- Redis may require a password, and it is not always in redis.conf -- a distro
     or a provisioning tool can set it elsewhere. Prefer the client's own config
     if one exists, so the probe does not need the secret itself. --}}
REDIS_CLI="redis-cli"
if [ -f /root/.rediscli_auth ]; then
    REDIS_CLI="redis-cli -a $(sudo cat /root/.rediscli_auth 2>/dev/null) --no-auth-warning"
elif sudo test -r /etc/redis/redis.conf; then
    {{-- redis.conf usually includes a drop-in, and a provisioning tool commonly
         puts the password there rather than in the main file, so both are read. --}}
    {{-- sudo sh -c so the glob expands as root: the login user usually cannot
         list conf.d, and a glob it cannot read silently expands to nothing. --}}
    REDIS_PW=$(sudo sh -c 'grep -shE "^[[:space:]]*requirepass" /etc/redis/redis.conf /etc/redis/conf.d/*.conf' 2>/dev/null | tail -n1 | awk '{print $2}' | tr -d '"')
    [ -n "$REDIS_PW" ] && REDIS_CLI="redis-cli -a $REDIS_PW --no-auth-warning"
fi
echo "redis_version:$($REDIS_CLI INFO server 2>/dev/null | awk -F: '/^redis_version:/{print $2}' | tr -d '\r' || echo '')"
echo "redis_maxmemory:$($REDIS_CLI CONFIG GET maxmemory 2>/dev/null | tail -n1 || echo '')"
echo "redis_maxmemory_policy:$($REDIS_CLI CONFIG GET maxmemory-policy 2>/dev/null | tail -n1 || echo '')"
echo "redis_tcp_backlog:$($REDIS_CLI CONFIG GET tcp-backlog 2>/dev/null | tail -n1 || echo '')"
echo "redis_io_threads:$($REDIS_CLI CONFIG GET io-threads 2>/dev/null | tail -n1 || echo '')"
@endif

{{-- Current kernel values, so a setting already correct is reported as such
     rather than proposed again on every analysis. --}}
@foreach (['net.core.somaxconn', 'net.core.netdev_max_backlog', 'net.ipv4.tcp_max_syn_backlog', 'net.ipv4.tcp_tw_reuse', 'net.ipv4.tcp_fin_timeout', 'vm.swappiness', 'fs.file-max'] as $key)
{{-- Every separator becomes an underscore: fs.file-max keeps a hyphen if only
     dots are replaced, and the parser drops any key that is not plain. --}}
echo "sysctl_{{ preg_replace('/[^a-z0-9]+/i', '_', $key) }}:$(sysctl -n {{ $key }} 2>/dev/null || echo '')"
@endforeach

@if ($nginxInstalled)
{{-- Read across nginx.conf and conf.d, since a value may already have been set
     in a drop-in. The last match wins, matching how nginx itself resolves them. --}}
@foreach (['worker_processes', 'worker_connections', 'keepalive_timeout', 'client_max_body_size', 'gzip', 'gzip_comp_level', 'server_tokens', 'open_file_cache'] as $directive)
echo "nginx_{{ $directive }}:$(grep -shE '^[[:space:]]*{{ $directive }}[[:space:]]' /etc/nginx/nginx.conf /etc/nginx/conf.d/*.conf 2>/dev/null | tail -n1 | sed -E 's/^[[:space:]]*{{ $directive }}[[:space:]]+//; s/;.*$//' | tr -d '\r' || echo '')"
@endforeach
@endif
