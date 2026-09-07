{{ $logDirectory }}/*.log {
    daily
    rotate 14
    maxsize 100M
    missingok
    notifempty
    compress
    delaycompress
    copytruncate
    su root root
}
