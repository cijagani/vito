import { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { LoaderCircleIcon, TriangleAlertIcon } from 'lucide-react';
import InputError from '@/components/ui/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Form, FormField } from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Site } from '@/types/site';

type RuntimeTuningForm = {
  max_upload_size: string;
  max_execution_time: string;
  memory_limit: string;
  max_input_vars: string;
  fpm_process_manager: 'dynamic' | 'ondemand';
  fpm_max_children: string;
  fpm_start_servers: string;
  fpm_min_spare_servers: string;
  fpm_max_spare_servers: string;
  fpm_idle_timeout_seconds: string;
  fpm_max_requests: string;
  request_timeout_seconds: string;
  slow_request_seconds: string;
  cpu_quota_percent: string;
  memory_high_mb: string;
  memory_max_mb: string;
  tasks_max: string;
  disk_quota_mb: string;
  client_max_body_size_mb: string;
  fastcgi_read_timeout_seconds: string;
  proxy_connect_timeout_seconds: string;
  proxy_read_timeout_seconds: string;
  static_cache_policy: 'disabled' | 'default' | 'aggressive';
  rate_limit_profile: 'off' | 'standard' | 'strict';
  access_log_enabled: boolean;
};

function value(number: number | null | undefined): string {
  return number?.toString() ?? '';
}

function nullableNumber(number: string): number | null {
  return number === '' ? null : Number(number);
}

function NumericField({ id, label, hint, min, max, value, error, onChange }: {
  id: keyof RuntimeTuningForm;
  label: string;
  hint: string;
  min: number;
  max: number;
  value: string;
  error?: string;
  onChange: (value: string) => void;
}) {
  return (
    <FormField>
      <Label htmlFor={id}>{label}</Label>
      <Input id={id} type="number" min={min} max={max} value={value} onChange={(event) => onChange(event.target.value)} />
      <p className="text-muted-foreground text-xs">{hint}</p>
      <InputError message={error} />
    </FormField>
  );
}

export default function PhpSettingsDialog({ open, onOpenChange, site }: { open: boolean; onOpenChange: (open: boolean) => void; site: Site }) {
  const tuning = site.runtime_tuning;
  const form = useForm<RuntimeTuningForm>({
    max_upload_size: value(site.php_settings.max_upload_size),
    max_execution_time: value(site.php_settings.max_execution_time),
    memory_limit: value(site.php_settings.memory_limit),
    max_input_vars: value(site.php_settings.max_input_vars),
    fpm_process_manager: tuning?.fpm_process_manager ?? 'dynamic',
    fpm_max_children: value(tuning?.fpm_max_children ?? 5),
    fpm_start_servers: value(tuning?.fpm_start_servers),
    fpm_min_spare_servers: value(tuning?.fpm_min_spare_servers),
    fpm_max_spare_servers: value(tuning?.fpm_max_spare_servers),
    fpm_idle_timeout_seconds: value(tuning?.fpm_idle_timeout_seconds ?? 10),
    fpm_max_requests: value(tuning?.fpm_max_requests ?? 500),
    request_timeout_seconds: value(tuning?.request_timeout_seconds ?? 60),
    slow_request_seconds: value(tuning?.slow_request_seconds),
    cpu_quota_percent: value(tuning?.cpu_quota_percent),
    memory_high_mb: value(tuning?.memory_high_mb),
    memory_max_mb: value(tuning?.memory_max_mb),
    tasks_max: value(tuning?.tasks_max),
    disk_quota_mb: value(tuning?.disk_quota_mb),
    client_max_body_size_mb: value(tuning?.client_max_body_size_mb),
    fastcgi_read_timeout_seconds: value(tuning?.fastcgi_read_timeout_seconds),
    proxy_connect_timeout_seconds: value(tuning?.proxy_connect_timeout_seconds),
    proxy_read_timeout_seconds: value(tuning?.proxy_read_timeout_seconds),
    static_cache_policy: tuning?.static_cache_policy ?? 'default',
    rate_limit_profile: tuning?.rate_limit_profile ?? 'off',
    access_log_enabled: tuning?.access_log_enabled ?? true,
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    form.clearErrors();
    form.transform((data) => ({
      ...data,
      max_upload_size: nullableNumber(data.max_upload_size),
      max_execution_time: nullableNumber(data.max_execution_time),
      memory_limit: nullableNumber(data.memory_limit),
      max_input_vars: nullableNumber(data.max_input_vars),
      fpm_max_children: Number(data.fpm_max_children),
      fpm_start_servers: data.fpm_process_manager === 'dynamic' ? nullableNumber(data.fpm_start_servers) : null,
      fpm_min_spare_servers: data.fpm_process_manager === 'dynamic' ? nullableNumber(data.fpm_min_spare_servers) : null,
      fpm_max_spare_servers: data.fpm_process_manager === 'dynamic' ? nullableNumber(data.fpm_max_spare_servers) : null,
      fpm_idle_timeout_seconds: data.fpm_process_manager === 'ondemand' ? nullableNumber(data.fpm_idle_timeout_seconds) : null,
      fpm_max_requests: Number(data.fpm_max_requests),
      request_timeout_seconds: Number(data.request_timeout_seconds),
      slow_request_seconds: nullableNumber(data.slow_request_seconds),
      cpu_quota_percent: nullableNumber(data.cpu_quota_percent),
      memory_high_mb: nullableNumber(data.memory_high_mb),
      memory_max_mb: nullableNumber(data.memory_max_mb),
      tasks_max: nullableNumber(data.tasks_max),
      disk_quota_mb: nullableNumber(data.disk_quota_mb),
      client_max_body_size_mb: nullableNumber(data.client_max_body_size_mb),
      fastcgi_read_timeout_seconds: nullableNumber(data.fastcgi_read_timeout_seconds),
      proxy_connect_timeout_seconds: nullableNumber(data.proxy_connect_timeout_seconds),
      proxy_read_timeout_seconds: nullableNumber(data.proxy_read_timeout_seconds),
      rate_limit_profile: data.rate_limit_profile === 'off' ? null : data.rate_limit_profile,
    }));
    form.patch(route('site-settings.update-php-settings', { server: site.server_id, site: site.id }), {
      onSuccess: () => onOpenChange(false),
    });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl" onCloseAutoFocus={(event) => event.preventDefault()}>
        <DialogHeader>
          <DialogTitle>{tuning ? 'Isolation & runtime tuning' : 'PHP settings'}</DialogTitle>
          <DialogDescription>{tuning ? 'Validated per-site PHP-FPM and webserver settings. Saving reloads both services with rollback on failure.' : 'Configure PHP limits for this legacy site.'}</DialogDescription>
        </DialogHeader>

        {tuning?.capacity_warning && (
          <div className="border-warning/40 bg-warning/10 flex gap-2 rounded-md border p-3 text-sm">
            <TriangleAlertIcon className="mt-0.5 size-4 shrink-0" />
            Theoretical FPM memory is {tuning.aggregate_fpm_memory_mb} MB, above 80% of the latest measured {tuning.server_memory_mb} MB. This is a capacity warning, not measured usage.
          </div>
        )}

        <Form id="php-settings-form" onSubmit={submit} className="space-y-6 p-4">
          {tuning && <section className="space-y-3">
            <div>
              <h3 className="font-medium">PHP-FPM processes</h3>
              <p className="text-muted-foreground text-xs">Estimated maximum for this pool: {tuning.estimated_fpm_memory_mb} MB.</p>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField>
                <Label htmlFor="fpm_process_manager">Process manager</Label>
                <Select value={form.data.fpm_process_manager} onValueChange={(selected: 'dynamic' | 'ondemand') => form.setData('fpm_process_manager', selected)}>
                  <SelectTrigger id="fpm_process_manager"><SelectValue /></SelectTrigger>
                  <SelectContent><SelectItem value="dynamic">Dynamic</SelectItem><SelectItem value="ondemand">On demand</SelectItem></SelectContent>
                </Select>
                <InputError message={form.errors.fpm_process_manager} />
              </FormField>
              <NumericField id="fpm_max_children" label="Max children" hint="Maximum concurrent PHP requests." min={1} max={500} value={form.data.fpm_max_children} error={form.errors.fpm_max_children} onChange={(next) => form.setData('fpm_max_children', next)} />
              {form.data.fpm_process_manager === 'dynamic' ? (
                <>
                  <NumericField id="fpm_start_servers" label="Start servers" hint="Workers created when FPM starts." min={1} max={500} value={form.data.fpm_start_servers} error={form.errors.fpm_start_servers} onChange={(next) => form.setData('fpm_start_servers', next)} />
                  <NumericField id="fpm_min_spare_servers" label="Minimum spare" hint="Minimum idle workers." min={1} max={500} value={form.data.fpm_min_spare_servers} error={form.errors.fpm_min_spare_servers} onChange={(next) => form.setData('fpm_min_spare_servers', next)} />
                  <NumericField id="fpm_max_spare_servers" label="Maximum spare" hint="Maximum idle workers." min={1} max={500} value={form.data.fpm_max_spare_servers} error={form.errors.fpm_max_spare_servers} onChange={(next) => form.setData('fpm_max_spare_servers', next)} />
                </>
              ) : (
                <NumericField id="fpm_idle_timeout_seconds" label="Idle timeout (seconds)" hint="Idle workers exit after this delay." min={1} max={3600} value={form.data.fpm_idle_timeout_seconds} error={form.errors.fpm_idle_timeout_seconds} onChange={(next) => form.setData('fpm_idle_timeout_seconds', next)} />
              )}
              <NumericField id="fpm_max_requests" label="Max requests per child" hint="Recycle workers to limit long-lived growth." min={1} max={100000} value={form.data.fpm_max_requests} error={form.errors.fpm_max_requests} onChange={(next) => form.setData('fpm_max_requests', next)} />
              <NumericField id="request_timeout_seconds" label="Request timeout (seconds)" hint="Hard FPM request termination limit." min={1} max={3600} value={form.data.request_timeout_seconds} error={form.errors.request_timeout_seconds} onChange={(next) => form.setData('request_timeout_seconds', next)} />
              <NumericField id="slow_request_seconds" label="Slow log threshold" hint="Optional; must be below request timeout." min={1} max={3600} value={form.data.slow_request_seconds} error={form.errors.slow_request_seconds} onChange={(next) => form.setData('slow_request_seconds', next)} />
            </div>
          </section>}

          {tuning?.fpm_service_mode === 'dedicated_master' && <section className="space-y-3">
            <div>
              <h3 className="font-medium">Systemd resource limits</h3>
              <p className="text-muted-foreground text-xs">Applied to this site's dedicated PHP-FPM service and slice.</p>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <NumericField id="cpu_quota_percent" label="CPU quota (%)" hint="Optional total CPU ceiling; 100% equals one CPU." min={1} max={1000} value={form.data.cpu_quota_percent} error={form.errors.cpu_quota_percent} onChange={(next) => form.setData('cpu_quota_percent', next)} />
              <NumericField id="tasks_max" label="Maximum tasks" hint="Optional maximum processes and threads." min={1} max={1000000} value={form.data.tasks_max} error={form.errors.tasks_max} onChange={(next) => form.setData('tasks_max', next)} />
              <NumericField id="memory_high_mb" label="Memory high (MB)" hint="Optional pressure threshold before the hard limit." min={1} max={1048576} value={form.data.memory_high_mb} error={form.errors.memory_high_mb} onChange={(next) => form.setData('memory_high_mb', next)} />
              <NumericField id="memory_max_mb" label="Memory max (MB)" hint="Optional hard cgroup memory limit." min={1} max={1048576} value={form.data.memory_max_mb} error={form.errors.memory_max_mb} onChange={(next) => form.setData('memory_max_mb', next)} />
            </div>
          </section>}

          {tuning && <section className="space-y-3">
            <div>
              <h3 className="font-medium">Filesystem quota</h3>
              <p className="text-muted-foreground text-xs">A hard limit for files owned by this site's Linux user on the site-storage filesystem. Nginx access and error logs are owned separately and are not charged to it.</p>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <NumericField id="disk_quota_mb" label="Disk quota (MB)" hint="Optional hard filesystem limit; requires one Linux user per site." min={1} max={1048576} value={form.data.disk_quota_mb} error={form.errors.disk_quota_mb} onChange={(next) => form.setData('disk_quota_mb', next)} />
            </div>
          </section>}

          <section className="space-y-3">
            <h3 className="font-medium">PHP limits</h3>
            <div className="grid gap-4 sm:grid-cols-2">
              <NumericField id="max_upload_size" label="Upload size (MB)" hint="PHP upload and post limit." min={1} max={10240} value={form.data.max_upload_size} error={form.errors.max_upload_size} onChange={(next) => form.setData('max_upload_size', next)} />
              <NumericField id="max_execution_time" label="Execution time (seconds)" hint="PHP script execution limit." min={1} max={3600} value={form.data.max_execution_time} error={form.errors.max_execution_time} onChange={(next) => form.setData('max_execution_time', next)} />
              <NumericField id="memory_limit" label="Memory per request (MB)" hint="Used for PHP and capacity estimates." min={16} max={8192} value={form.data.memory_limit} error={form.errors.memory_limit} onChange={(next) => form.setData('memory_limit', next)} />
              <NumericField id="max_input_vars" label="Max input variables" hint="Maximum submitted form variables." min={100} max={100000} value={form.data.max_input_vars} error={form.errors.max_input_vars} onChange={(next) => form.setData('max_input_vars', next)} />
            </div>
          </section>

          {tuning && <section className="space-y-3">
            <h3 className="font-medium">Webserver</h3>
            <div className="grid gap-4 sm:grid-cols-2">
              <NumericField id="client_max_body_size_mb" label="Request body (MB)" hint="Nginx/Caddy request-body ceiling." min={1} max={10240} value={form.data.client_max_body_size_mb} error={form.errors.client_max_body_size_mb} onChange={(next) => form.setData('client_max_body_size_mb', next)} />
              <NumericField id="fastcgi_read_timeout_seconds" label="FastCGI read timeout" hint="How long the webserver waits for PHP." min={1} max={3600} value={form.data.fastcgi_read_timeout_seconds} error={form.errors.fastcgi_read_timeout_seconds} onChange={(next) => form.setData('fastcgi_read_timeout_seconds', next)} />
              <NumericField id="proxy_connect_timeout_seconds" label="Proxy connect timeout" hint="Optional reverse-proxy connection limit." min={1} max={600} value={form.data.proxy_connect_timeout_seconds} error={form.errors.proxy_connect_timeout_seconds} onChange={(next) => form.setData('proxy_connect_timeout_seconds', next)} />
              <NumericField id="proxy_read_timeout_seconds" label="Proxy read timeout" hint="Optional reverse-proxy response limit." min={1} max={3600} value={form.data.proxy_read_timeout_seconds} error={form.errors.proxy_read_timeout_seconds} onChange={(next) => form.setData('proxy_read_timeout_seconds', next)} />
              <FormField>
                <Label htmlFor="static_cache_policy">Static cache policy</Label>
                <Select value={form.data.static_cache_policy} onValueChange={(selected: RuntimeTuningForm['static_cache_policy']) => form.setData('static_cache_policy', selected)}>
                  <SelectTrigger id="static_cache_policy"><SelectValue /></SelectTrigger>
                  <SelectContent><SelectItem value="disabled">Disabled</SelectItem><SelectItem value="default">7 days</SelectItem><SelectItem value="aggressive">30 days immutable</SelectItem></SelectContent>
                </Select>
                <InputError message={form.errors.static_cache_policy} />
              </FormField>
              {site.webserver === 'nginx' && (
                <FormField>
                  <Label htmlFor="rate_limit_profile">Rate limit</Label>
                  <Select value={form.data.rate_limit_profile} onValueChange={(selected: RuntimeTuningForm['rate_limit_profile']) => form.setData('rate_limit_profile', selected)}>
                    <SelectTrigger id="rate_limit_profile"><SelectValue /></SelectTrigger>
                    <SelectContent><SelectItem value="off">Off</SelectItem><SelectItem value="standard">Standard (20 requests/s)</SelectItem><SelectItem value="strict">Strict (5 requests/s)</SelectItem></SelectContent>
                  </Select>
                  <InputError message={form.errors.rate_limit_profile} />
                </FormField>
              )}
              <FormField className="flex items-center justify-between gap-4 rounded-md border p-3 sm:col-span-2">
                <div><Label htmlFor="access_log_enabled">Access log</Label><p className="text-muted-foreground text-xs">Keep per-site request logging enabled.</p></div>
                <Switch id="access_log_enabled" checked={form.data.access_log_enabled} onCheckedChange={(checked) => form.setData('access_log_enabled', checked)} />
              </FormField>
            </div>
          </section>}
        </Form>

        <DialogFooter className="gap-2">
          <DialogClose asChild><Button variant="outline">Cancel</Button></DialogClose>
          <Button form="php-settings-form" type="submit" disabled={form.processing}>
            {form.processing && <LoaderCircleIcon className="size-4 animate-spin" />}
            Validate and apply
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
