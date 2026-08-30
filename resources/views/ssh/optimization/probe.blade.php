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
echo "mysql_innodb_buffer_pool_size:$(mysql -N -B -e 'SELECT @@innodb_buffer_pool_size' 2>/dev/null || echo '')"
echo "mysql_max_connections:$(mysql -N -B -e 'SELECT @@max_connections' 2>/dev/null || echo '')"
echo "mysql_flush_method:$(mysql -N -B -e 'SELECT @@innodb_flush_method' 2>/dev/null || echo '')"
@endif

@if ($redisInstalled)
echo "redis_maxmemory:$(redis-cli CONFIG GET maxmemory 2>/dev/null | tail -n1 || echo '')"
echo "redis_maxmemory_policy:$(redis-cli CONFIG GET maxmemory-policy 2>/dev/null | tail -n1 || echo '')"
@endif

@if ($nginxInstalled)
echo "nginx_worker_processes:$(grep -sE '^\s*worker_processes' /etc/nginx/nginx.conf | awk '{print $2}' | tr -d ';' || echo '')"
echo "nginx_worker_connections:$(grep -sE '^\s*worker_connections' /etc/nginx/nginx.conf | awk '{print $2}' | tr -d ';' || echo '')"
@endif
