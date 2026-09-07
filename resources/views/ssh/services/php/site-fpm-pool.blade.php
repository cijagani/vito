[{{ $poolName }}]
user = {{ $siteUser }}
group = {{ $siteUser }}

listen = {{ $socketPath }}
listen.owner = {{ $webserverUser }}
listen.group = {{ $webserverUser }}
listen.mode = 0660

pm = {{ $processManager }}
pm.max_children = {{ $maxChildren }}
@if ($processManager === 'dynamic')
pm.start_servers = {{ $startServers }}
pm.min_spare_servers = {{ $minSpareServers }}
pm.max_spare_servers = {{ $maxSpareServers }}
@else
pm.process_idle_timeout = {{ $idleTimeoutSeconds }}s
@endif
pm.max_requests = {{ $maxRequests }}

request_terminate_timeout = {{ $requestTimeoutSeconds }}s
@if ($slowRequestSeconds !== null)
request_slowlog_timeout = {{ $slowRequestSeconds }}s
slowlog = {{ $slowLogPath }}
@endif
catch_workers_output = yes

env[TMPDIR] = {{ $temporaryPath }}
php_admin_value[open_basedir] = {{ $siteHome }}/
php_admin_value[upload_tmp_dir] = {{ $temporaryPath }}
php_admin_value[session.save_path] = {{ $temporaryPath }}
php_admin_value[display_errors] = off
php_admin_value[log_errors] = on
php_admin_value[error_log] = {{ $errorLogPath }}
@if ($memoryLimitMb !== null)
php_admin_value[memory_limit] = {{ $memoryLimitMb }}M
@endif
@if ($maxExecutionTimeSeconds !== null)
php_admin_value[max_execution_time] = {{ $maxExecutionTimeSeconds }}
@endif
@if ($maxInputTimeSeconds !== null)
php_admin_value[max_input_time] = {{ $maxInputTimeSeconds }}
@endif
@if ($maxInputVars !== null)
php_admin_value[max_input_vars] = {{ $maxInputVars }}
@endif
@if ($postMaxSizeMb !== null)
php_admin_value[post_max_size] = {{ $postMaxSizeMb }}M
@endif
@if ($uploadMaxFilesizeMb !== null)
php_admin_value[upload_max_filesize] = {{ $uploadMaxFilesizeMb }}M
@endif
