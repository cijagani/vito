display_errors=Off
log_errors=On
open_basedir="/home/{{ $siteUser }}/"
upload_tmp_dir="{{ $temporaryPath }}"
session.save_path="{{ $temporaryPath }}"
@if ($memoryLimitMb !== null)
memory_limit={{ $memoryLimitMb }}M
@endif
@if ($maxExecutionTimeSeconds !== null)
max_execution_time={{ $maxExecutionTimeSeconds }}
@endif
@if ($maxInputTimeSeconds !== null)
max_input_time={{ $maxInputTimeSeconds }}
@endif
@if ($maxInputVars !== null)
max_input_vars={{ $maxInputVars }}
@endif
@if ($postMaxSizeMb !== null)
post_max_size={{ $postMaxSizeMb }}M
@endif
@if ($uploadMaxFilesizeMb !== null)
upload_max_filesize={{ $uploadMaxFilesizeMb }}M
@endif
