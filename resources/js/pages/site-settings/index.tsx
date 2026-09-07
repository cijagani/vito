import { Head, router, usePage } from '@inertiajs/react';
import { Server } from '@/types/server';
import Container from '@/components/container';
import HeaderContainer from '@/components/header-container';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import ServerLayout from '@/layouts/server/layout';
import SiteBanners from '@/components/site-banners';
import { BookOpenIcon, TriangleAlertIcon } from 'lucide-react';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import DateTime from '@/components/date-time';
import React from 'react';
import { Site } from '@/types/site';
import { Badge } from '@/components/ui/badge';
import ChangeBranch from '@/pages/site-settings/components/branch';
import { SourceControl } from '@/types/source-control';
import CopyableBadge from '@/components/copyable-badge';
import ChangePHPVersion from '@/pages/site-settings/components/php-version';
import DeleteSite from '@/pages/site-settings/components/delete-site';
import VHost from '@/pages/site-settings/components/vhost';
import VHostPreview from '@/pages/site-settings/components/vhost-preview';
import ChangeSourceControl from '@/pages/site-settings/components/source-control';
import BasicAuth from '@/pages/site-settings/components/basic-auth';
import StatsToggle from '@/pages/site-settings/components/stats-toggle';
import WebDirectory from './components/web-directory';
import { useDialog } from '@/hooks/use-dialog';

export default function Databases() {
  const dialog = useDialog();
  const page = usePage<{
    server: Server;
    site: Site;
    sourceControl?: SourceControl;
  }>();

  return (
    <ServerLayout>
      <Head title={`Settings - ${page.props.site.domain}`} />

      <Container className="max-w-5xl">
        <HeaderContainer>
          <Heading title="Settings" description="Here you can manage your site's settings" />
          <div className="flex items-center gap-2">
            <a href="https://vitodeploy.com/docs/sites/settings" target="_blank">
              <Button variant="outline">
                <BookOpenIcon />
                <span className="hidden lg:block">Docs</span>
              </Button>
            </a>
          </div>
        </HeaderContainer>

        <SiteBanners site={page.props.site} />

        <Card className="overflow-hidden">
          <CardHeader className="flex-row items-center justify-between gap-2">
            <div className="space-y-2">
              <CardTitle>Site details</CardTitle>
              <CardDescription>Update site details</CardDescription>
            </div>
          </CardHeader>
          <CardContent className="bg-background">
            <div className="flex items-center justify-between p-4">
              <span>ID</span>
              <span className="text-muted-foreground">{page.props.site.id}</span>
            </div>
            <Separator />
            <div className="flex items-center justify-between p-4">
              <span>Domain</span>
              <a href={page.props.site.url} target="_blank" className="text-muted-foreground hover:underline">
                {page.props.site.domain}
              </a>
            </div>
            <Separator />
            <div className="flex items-center justify-between p-4">
              <span>Type</span>
              <span className="text-muted-foreground">{page.props.site.type}</span>
            </div>
            <Separator />
            <div className="flex items-center justify-between p-4">
              <span>Source control</span>
              {page.props.site.source_control_id ? (
                <ChangeSourceControl site={page.props.site}>
                  <Button variant="outline" className="h-6">
                    {page.props.sourceControl?.provider}
                  </Button>
                </ChangeSourceControl>
              ) : (
                <span className="text-muted-foreground">-</span>
              )}
            </div>
            <Separator />
            <div className="flex items-center justify-between p-4">
              <span>Repository</span>
              <span className="text-muted-foreground">{page.props.site.repository || '-'}</span>
            </div>
            <Separator />
            <div className="flex items-center justify-between p-4">
              <span>Branch</span>
              {page.props.site.source_control_id ? (
                <ChangeBranch site={page.props.site}>
                  <Button variant="outline" className="h-6">
                    {page.props.site.branch}
                  </Button>
                </ChangeBranch>
              ) : (
                '-'
              )}
            </div>
            <Separator />
            <div className="flex items-center justify-between p-4">
              <span>VHost</span>
              <VHostPreview site={page.props.site}>
                <Button variant="outline" className="h-6">
                  View VHost
                </Button>
              </VHostPreview>
            </div>
            <Separator />
            <div className="flex items-center justify-between p-4">
              <div className="flex items-center gap-2">
                <span>VHost Template</span>
                {page.props.site.has_custom_vhost_template && (
                  <TooltipProvider delayDuration={0}>
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <TriangleAlertIcon className="text-destructive h-4 w-4" />
                      </TooltipTrigger>
                      <TooltipContent>You are using a custom vhost template</TooltipContent>
                    </Tooltip>
                  </TooltipProvider>
                )}
              </div>
              <VHost site={page.props.site}>
                <Button variant="outline" className="h-6">
                  Edit Template
                </Button>
              </VHost>
            </div>
            {(page.props.site.webserver === 'nginx' || page.props.site.webserver === 'caddy') && (
              <>
                <Separator />
                <div className="flex items-center justify-between p-4">
                  <span>Basic Auth</span>
                  <BasicAuth site={page.props.site}>
                    <Button variant="outline" className="h-6">
                      {page.props.site.basic_auth?.enabled ? `Enabled (${page.props.site.basic_auth.users.length})` : 'Disabled'}
                    </Button>
                  </BasicAuth>
                </div>
              </>
            )}
            {page.props.server.services['log_analysis'] && (
              <>
                <Separator />
                <div className="flex items-center justify-between p-4">
                  <span>Statistics</span>
                  <StatsToggle site={page.props.site}>
                    <Button variant="outline" className="h-6">
                      {page.props.site.stats_enabled ? 'Enabled' : 'Disabled'}
                    </Button>
                  </StatsToggle>
                </div>
              </>
            )}
            <Separator />
            <div className="flex items-center justify-between p-4">
              <span>Web directory</span>
              <WebDirectory site={page.props.site}>
                <Button variant="outline" className="h-6">
                  {page.props.site.web_directory || '/'}
                </Button>
              </WebDirectory>
            </div>
            <Separator />
            <div className="flex items-center justify-between p-4">
              <span>Path</span>
              <CopyableBadge text={page.props.site.path} />
            </div>
            <Separator />
            <div className="flex items-center justify-between p-4">
              <span>PHP version</span>
              {page.props.site.php_version ? (
                <div className="flex items-center gap-2">
                  <ChangePHPVersion site={page.props.site}>
                    <Button variant="outline" className="h-6">
                      {page.props.site.php_version}
                    </Button>
                  </ChangePHPVersion>
                  {page.props.site.supports_php_settings && (
                    <Button
                      variant="outline"
                      className="h-6"
                      aria-label="Configure PHP settings"
                      onClick={() => dialog.phpSettings.open({ site: page.props.site })}
                    >
                      Configure
                    </Button>
                  )}
                </div>
              ) : (
                <span className="text-muted-foreground">-</span>
              )}
            </div>
            <Separator />
            <div className="flex items-center justify-between p-4">
              <span>Status</span>
              <Badge variant={page.props.site.status_color}>{page.props.site.status}</Badge>
            </div>
            <Separator />
            <div className="flex items-center justify-between p-4">
              <span>Created at</span>
              <span className="text-muted-foreground">
                <DateTime date={page.props.site.created_at} />
              </span>
            </div>
          </CardContent>
        </Card>

        {page.props.site.runtime_tuning && (
          <Card className="overflow-hidden">
            <CardHeader className="flex-row items-center justify-between gap-3">
              <div className="space-y-2">
                <CardTitle>Isolation & runtime</CardTitle>
                <CardDescription>Effective site boundary, capacity estimate, and applied configuration state.</CardDescription>
              </div>
              <div className="flex gap-2">
                <Button variant="outline" onClick={() => dialog.runtimePreview.open({ site: page.props.site })}>Preview</Button>
                <Button
                  variant="outline"
                  disabled={!page.props.site.runtime_tuning.runtime_drifted && !page.props.site.runtime_tuning.web_drifted}
                  onClick={() => router.post(route('site-settings.runtime-repair', { server: page.props.site.server_id, site: page.props.site.id }))}
                >
                  Repair drift
                </Button>
              </div>
            </CardHeader>
            <CardContent className="bg-background">
                <div className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <div><p className="text-muted-foreground text-xs">Isolation profile</p><p className="font-medium capitalize">{page.props.site.runtime_tuning.isolation_profile.replace('_', ' ')}</p></div>
                <div><p className="text-muted-foreground text-xs">FPM manager</p><p className="font-medium capitalize">{page.props.site.runtime_tuning.fpm_process_manager}</p></div>
                <div><p className="text-muted-foreground text-xs">Effective socket</p><p className="truncate font-mono text-xs">{page.props.site.runtime_tuning.effective_socket}</p></div>
                <div><p className="text-muted-foreground text-xs">Theoretical pool memory</p><p className="font-medium">{page.props.site.runtime_tuning.estimated_fpm_memory_mb} MB</p></div>
                </div>
                {page.props.site.runtime_tuning.observed_at && (
                  <div className="grid gap-4 border-t p-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div><p className="text-muted-foreground text-xs">FPM memory now</p><p className="font-medium">{page.props.site.runtime_tuning.observed_memory_current_mb ?? '—'}{page.props.site.runtime_tuning.observed_memory_current_mb !== null && ' MB'}</p></div>
                    <div><p className="text-muted-foreground text-xs">FPM memory peak</p><p className="font-medium">{page.props.site.runtime_tuning.observed_memory_peak_mb ?? '—'}{page.props.site.runtime_tuning.observed_memory_peak_mb !== null && ' MB'}</p></div>
                    <div><p className="text-muted-foreground text-xs">FPM tasks</p><p className="font-medium">{page.props.site.runtime_tuning.observed_tasks_current ?? '—'}</p></div>
                    <div><p className="text-muted-foreground text-xs">Cgroup OOM kills</p><p className="font-medium">{page.props.site.runtime_tuning.observed_oom_kill_count ?? '—'}</p></div>
                  </div>
                )}
                <Separator />
              <div className="flex flex-wrap gap-2 p-4 text-sm">
                <Badge variant={page.props.site.runtime_tuning.runtime_drifted ? 'warning' : 'success'}>FPM {page.props.site.runtime_tuning.runtime_drifted ? 'drifted' : 'applied'}</Badge>
                <Badge variant={page.props.site.runtime_tuning.web_drifted ? 'warning' : 'success'}>Web {page.props.site.runtime_tuning.web_drifted ? 'drifted' : 'applied'}</Badge>
                {page.props.site.runtime_tuning.capacity_warning && <Badge variant="warning">Capacity warning</Badge>}
              </div>
            </CardContent>
          </Card>
        )}

        <Card className="border-destructive/50 overflow-hidden">
          <CardHeader>
            <CardTitle>Delete site</CardTitle>
            <CardDescription>Here you can delete the site.</CardDescription>
          </CardHeader>
          <CardContent className="bg-background">
            <div className="space-y-2 p-4">
              <p>please note that this action is irreversible and will delete all data associated with the site.</p>

              <DeleteSite site={page.props.site}>
                <Button variant="destructive">Delete site</Button>
              </DeleteSite>
            </div>
          </CardContent>
        </Card>
      </Container>
    </ServerLayout>
  );
}
