export VITO_SITE_ID={!! escapeshellarg((string) $siteId) !!}
export PHP_VERSION={!! escapeshellarg($phpVersion) !!}
export PHP_BINARY={!! escapeshellarg($phpBinary) !!}
export PHP_INI_SCAN_DIR={!! escapeshellarg($iniScanDirectory) !!}
export TMPDIR={!! escapeshellarg($temporaryPath) !!}
export PATH={!! escapeshellarg($siteBin) !!}:$PATH
