[Unit]
Description={{ $description }}
After=network.target

[Service]
Type=simple
Slice={{ $sliceUnit }}
ExecStart={{ $fpmBinary }} --nodaemonize --fpm-config {{ $configPath }}
Restart=on-failure
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
