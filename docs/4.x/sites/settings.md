# Settings

## Introduction

In the Settings page you can manage your site's details, its PHP version and PHP settings, web
directory, vhost template, basic auth, and more.

## Site details

The Settings page shows read-only details for the site, including its ID, domain, type, repository,
path, status, and creation date. Several of these have inline controls to change them, described
below.

## Change PHP Version

You can change the PHP version of each website in their Settings page.

Make sure that the PHP version you want to use is already installed in the [PHP](../servers/php#install-and-uninstall)
page.

## Configure PHP and runtime settings

For managed PHP sites, click **Configure** next to the PHP version to change the site's PHP limits.
An isolated site also gets typed PHP-FPM and webserver controls.

PHP limits include upload size, execution time, memory per request, and maximum input variables.
For an isolated site, Vito writes these values to both the site-specific FPM pool and its CLI INI
profile. Deployments, one-off commands, Composer, workers, and cron use the site's selected PHP
version and CLI profile. A legacy site without an isolated system user keeps the previous
vhost-based behavior.

The FPM controls support two process managers:

- **Dynamic** keeps a validated number of starting, minimum spare, and maximum spare workers.
- **On demand** starts workers when requests arrive and removes idle workers after the configured
  timeout.

Both modes support maximum children, child recycling, a hard request timeout, and an optional slow
request log threshold. The slow threshold must remain below the hard request timeout. Dynamic
values must satisfy `minimum spare <= start <= maximum spare <= maximum children`.

Webserver controls include request-body and upstream timeouts, per-site access logging, and a static
asset cache policy. Nginx sites can additionally select a validated standard or strict request-rate
profile. Rate limiting is not currently offered for Caddy sites.

Leaving an optional PHP or webserver limit empty uses the installed service's default. Vito does not
publish universal optimization presets because safe values depend on measured application traffic,
latency, and memory use.

### Capacity and configuration state

The **Isolation & runtime** card displays the effective FPM socket, the configured process manager,
and a theoretical maximum pool-memory estimate (`maximum children x memory per request`). It also
compares the aggregate estimate for PHP sites with the server's latest memory metric and warns when
the estimate exceeds 80 percent. This is a planning warning, not measured PHP memory consumption.

Use **Preview** to inspect the desired generated FPM and vhost configuration without changing the
server. If the stored desired revision differs from the last successfully applied revision, the card
shows drift and enables **Repair drift**. Repair validates and reapplies the managed configuration
using the same atomic apply, health check, and rollback path as a normal update.

:::warning
Configuration is available only for PHP sites that use automatic vhost generation and the default
vhost template. If you use a custom vhost template, add the directives to your template manually — a
banner warns you when stored PHP settings cannot be applied.
:::

## Change branch

You can change the branch of your cloned repository in the Settings page.

## Change source control

You can change the source control of your cloned repository in the Settings page.

## Web directory

You can change the web (public) directory of your site relative to its root path, for example
`/public` for a Laravel application. Updating it regenerates the vhost.

## Aliases

Site aliases are no longer managed here. In v4.x they are handled as alias
[domains](/docs/4.x/sites/domains), where you can add multiple alias and redirect domains per site,
each with its own DNS validation and SSL configuration.

## VHost

In v4.x the vhost is generated from a [Mustache](https://mustache.github.io) template, replacing the
custom-block model used in earlier versions. The Settings page gives you two related controls.

### View VHost

**View VHost** opens a read-only view of the vhost that is currently deployed to the server, so you
can see exactly what Vito generated.

### VHost Template

**Edit Template** opens the Mustache template used to generate the vhost. From here you can:

- **Edit** the template with syntax highlighting for your webserver (nginx or Caddy).
- **Preview** the generated output from your edits before saving, without touching the live config.
- **Reset** the template back to the default, which regenerates the vhost and discards your
  customizations.

Changes to the template persist across SSL, domain, and redirect updates. When a site uses a
customized template, the Settings page flags it so you know it differs from the default.

:::tip
See the [Mustache manual](https://mustache.github.io/mustache.5.html) for the template syntax.
:::

### VHost generation

Automatic vhost generation can be enabled or disabled per site. While it is disabled, changes to
SSL, domains, or redirects will not update the vhost on the server.

:::warning
On sites that existed before upgrading to v4.x, vhost generation is **disabled by default** so the
upgrade does not overwrite a working configuration. Review the template, then re-enable generation
(a banner on the site provides a quick **Re-enable** action). See
[Custom VHost Configuration](/docs/4.x/prologue/breaking-changes) for details.
:::

## Basic Auth

For sites served by nginx or Caddy, you can protect the site with HTTP Basic Authentication, adding
one or more username/password pairs. This is useful for staging and preview sites that must be
publicly reachable but not openly accessible. Basic auth is managed from the Settings page and shows
whether it is currently enabled and how many users are configured.

## Force SSL

Once a site has an active SSL certificate, you can enable **Force SSL** to redirect all HTTP traffic
to HTTPS. Toggle it from the Settings page. See the site's
[Domains](/docs/4.x/sites/domains#ssl-per-domain) page (per-domain certificates) or the server's
[SSL Certificates](/docs/4.x/servers/ssl) page (wildcard/custom certificates) for how to issue a
certificate first.

## Site Statistics

On servers that have the log analysis (GoAccess) service installed, you can enable **Statistics** for
a site to collect and visualize its web traffic. Enabling it configures log collection for the site;
disabling it stops collection. See [Monitoring](/docs/4.x/servers/monitoring) for the server-side
service.

## Delete

You can delete the website from your server.

This will delete the files of your website and the webserver configurations related to your website
from the server.
