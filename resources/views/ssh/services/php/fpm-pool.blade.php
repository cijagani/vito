[{{ $user }}]
user = {{ $user }}
group = {{ $user }}

listen = /run/php/php{{ $version }}-fpm-{{ $user }}.sock
listen.owner = vito
listen.group = vito
listen.mode = 0660

pm = dynamic
pm.max_children = 5
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 500

php_admin_value[open_basedir] = {{ $homeDirectory }}/:/tmp/
php_admin_value[upload_tmp_dir] = {{ $homeDirectory }}/tmp
php_admin_value[session.save_path] = {{ $homeDirectory }}/tmp
php_admin_value[display_errors] = off
php_admin_value[log_errors] = on
php_admin_value[error_log] = {{ $homeDirectory }}/.logs/php_errors.log
