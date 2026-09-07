[Slice]
@if ($cpuQuotaPercent !== null)
CPUQuota={{ $cpuQuotaPercent }}%
@endif
@if ($memoryHighMb !== null)
MemoryHigh={{ $memoryHighMb }}M
@endif
@if ($memoryMaxMb !== null)
MemoryMax={{ $memoryMaxMb }}M
@endif
@if ($tasksMax !== null)
TasksMax={{ $tasksMax }}
@endif
