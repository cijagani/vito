import { Site } from '@/types/site';
import { Button } from '@/components/ui/button';
import { LoaderCircleIcon } from 'lucide-react';
import { FormEvent } from 'react';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Form, FormField, FormFields } from '@/components/ui/form';
import { useForm } from '@inertiajs/react';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import InputError from '@/components/ui/input-error';

type LoadClassDialogProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  site: Site;
};

const options = [
  { value: 'low', label: 'Low', hint: 'Brochure, docs or a low-traffic blog. Mostly cached or static' },
  { value: 'medium', label: 'Medium', hint: 'A normal application with steady traffic' },
  { value: 'high', label: 'High', hint: 'The primary app on this server — API, dashboard, or heavy traffic' },
];

export default function LoadClassDialog({ open, onOpenChange, site }: LoadClassDialogProps) {
  const form = useForm({
    load_class: site.load_class || 'medium',
  });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.patch(route('site-settings.update-load-class', { server: site.server_id, site: site.id }), {
      onSuccess: () => onOpenChange(false),
      preserveScroll: true,
      preserveState: true,
    });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md" onCloseAutoFocus={(e) => e.preventDefault()}>
        <DialogHeader>
          <DialogTitle>Expected load</DialogTitle>
          <DialogDescription className="sr-only">How busy this site is</DialogDescription>
        </DialogHeader>

        <Form id="load-class-form" className="p-4" onSubmit={submit}>
          <FormFields>
            <FormField>
              <Label htmlFor="load_class">Expected load</Label>
              <Select value={form.data.load_class} onValueChange={(value) => form.setData('load_class', value)}>
                <SelectTrigger id="load_class">
                  <SelectValue placeholder="Select a load class" />
                </SelectTrigger>
                <SelectContent>
                  <SelectGroup>
                    {options.map((option) => (
                      <SelectItem key={option.value} value={option.value}>
                        {option.label}
                      </SelectItem>
                    ))}
                  </SelectGroup>
                </SelectContent>
              </Select>
              <p className="text-muted-foreground text-sm">
                {options.find((option) => option.value === form.data.load_class)?.hint}
              </p>
              <p className="text-muted-foreground text-xs">
                Busier sites receive a larger share of this server's PHP-FPM workers. Takes effect the next time the
                server is analyzed.
              </p>
              <InputError message={form.errors.load_class} />
            </FormField>
          </FormFields>
        </Form>

        <DialogFooter className="gap-2">
          <DialogClose asChild>
            <Button variant="outline">Cancel</Button>
          </DialogClose>

          <Button form="load-class-form" disabled={form.processing}>
            {form.processing && <LoaderCircleIcon className="size-4 animate-spin" />}
            Save
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
