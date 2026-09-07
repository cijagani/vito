import { useEffect, useState } from 'react';
import { Editor, useMonaco } from '@monaco-editor/react';
import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Sheet, SheetClose, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useAppearance } from '@/hooks/use-appearance';
import { registerCaddyLanguage, registerNginxLanguage } from '@/lib/editor';
import { Site } from '@/types/site';

export default function RuntimePreview({ open, onOpenChange, site }: { open: boolean; onOpenChange: (open: boolean) => void; site: Site }) {
  const { getActualAppearance } = useAppearance();
  const [view, setView] = useState<'fpm' | 'vhost'>('fpm');
  const query = useQuery<{ fpm: string; vhost: string }>({
    queryKey: ['site-settings.runtime-preview', site.server_id, site.id],
    queryFn: async () => (await axios.get(route('site-settings.runtime-preview', { server: site.server_id, site: site.id }))).data,
    retry: false,
    enabled: open,
    refetchOnWindowFocus: false,
  });
  const monaco = useMonaco();

  useEffect(() => {
    if (monaco) {
      registerNginxLanguage(monaco);
      registerCaddyLanguage(monaco);
    }
  }, [monaco]);

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="sm:max-w-5xl">
        <SheetHeader>
          <SheetTitle>Desired runtime configuration</SheetTitle>
          <SheetDescription>Read-only preview generated from the current typed profile.</SheetDescription>
          <div className="flex gap-2 pt-2">
            <Button size="sm" variant={view === 'fpm' ? 'default' : 'outline'} onClick={() => setView('fpm')}>PHP-FPM</Button>
            <Button size="sm" variant={view === 'vhost' ? 'default' : 'outline'} onClick={() => setView('vhost')}>VHost</Button>
          </div>
        </SheetHeader>
        <div className="h-full">
          {query.isSuccess ? (
            <Editor
              language={view === 'fpm' ? 'ini' : site.webserver}
              value={query.data[view]}
              theme={getActualAppearance() === 'dark' ? 'vs-dark' : 'vs'}
              className="h-full"
              options={{ fontSize: 15, readOnly: true, domReadOnly: true }}
            />
          ) : <Skeleton className="h-full w-full rounded-none" />}
        </div>
        <SheetFooter><SheetClose asChild><Button variant="outline">Close</Button></SheetClose></SheetFooter>
      </SheetContent>
    </Sheet>
  );
}
