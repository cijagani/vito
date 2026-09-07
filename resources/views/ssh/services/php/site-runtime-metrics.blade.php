set -eu

service_unit='{{ $serviceUnit }}'
control_group="$(systemctl show --property=ControlGroup --value "$service_unit" 2>/dev/null || true)"

case "$control_group" in
    /*) ;;
    *) exit 0 ;;
esac

case "$control_group" in
    *'..'*|*[!A-Za-z0-9_./-]*) exit 0 ;;
esac

if [ ! -f /sys/fs/cgroup/cgroup.controllers ]; then
    exit 0
fi

cgroup_path="/sys/fs/cgroup${control_group}"
if [ ! -d "$cgroup_path" ]; then
    exit 0
fi

read_number() {
    metric_file="$1"
    metric_key="$2"

    if [ ! -r "$metric_file" ]; then
        return 0
    fi

    metric_value="$(tr -d '\n' < "$metric_file")"
    case "$metric_value" in
        ''|*[!0-9]*) return 0 ;;
    esac

    printf '%s:%s\n' "$metric_key" "$metric_value"
}

read_number "$cgroup_path/memory.current" memory_current_bytes
read_number "$cgroup_path/memory.peak" memory_peak_bytes
read_number "$cgroup_path/pids.current" tasks_current

if [ -r "$cgroup_path/cpu.stat" ]; then
    cpu_usage_usec="$(awk '$1 == "usage_usec" { print $2; exit }' "$cgroup_path/cpu.stat")"
    case "$cpu_usage_usec" in
        ''|*[!0-9]*) ;;
        *) printf 'cpu_usage_usec:%s\n' "$cpu_usage_usec" ;;
    esac
fi

if [ -r "$cgroup_path/memory.events" ]; then
    oom_kill_count="$(awk '$1 == "oom_kill" { print $2; exit }' "$cgroup_path/memory.events")"
    case "$oom_kill_count" in
        ''|*[!0-9]*) ;;
        *) printf 'oom_kill_count:%s\n' "$oom_kill_count" ;;
    esac
fi
