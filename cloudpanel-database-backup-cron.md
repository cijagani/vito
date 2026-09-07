# CloudPanel Architecture — Database, Backup & Cron Management

> **Companion to** `cloudpanel-architecture.md` (sites, nginx, SSL, PHP-FPM, isolation).
> Same method: reverse-engineered from the **live CloudPanel 2.5.4 install** on this server
> (Ubuntu 24.04.4, Percona Server 8.4.10-10, ~30 sites), by reading the running system *and*
> decoding the obfuscated application source at `/home/clp/htdocs/app/files/src/`.
>
> Local modifications are marked **[LOCAL]**. Everything else is stock behaviour.
>
> Document generated: 2026-09-06

---

## ⚠️ Read This First — Live Audit Findings

Before the architecture, three facts about **this specific server**, discovered while
documenting it:

| Finding | Status | Impact |
|---|---|---|
| **Remote backup is not configured** | No `remote_backup_*` keys in `config`; no `/etc/cron.d/clp-rclone` | No off-server copy of anything |
| **`db:backup` has never run** | All `/home/*/backups/databases/` contain only `.gitignore` | **No database backups exist at all** |
| Panel self-backup exists | 3 dated dirs in `/home/clp/backups/` (2026-02-17, 02-18, 07-14) | Only protects the *panel*, not your data |

The three timestamps on the panel backups line up with CloudPanel update dates, not a
schedule — `create_backup.sh` runs on upgrade, not nightly.

**Net position: this server currently has no database backups and no off-site copies.**
See [§5.1](#51-minimum-viable-backup-for-this-server) for the two commands that fix it.

---

## Table of Contents

1. [Database Management](#1-database-management)
2. [Backup Management](#2-backup-management)
3. [Cron Management](#3-cron-management)
4. [Live State of This Server](#4-live-state-of-this-server)
5. [Operational Recipes](#5-operational-recipes)
6. [Blueprint: Building Your Own](#6-blueprint-building-your-own)
7. [Command Reference](#7-command-reference)

---

## 1. Database Management

### 1.1 The model: one shared server, isolation by grants

CloudPanel does **not** give each site its own database server. There is one MySQL/Percona
(or MariaDB) instance, and tenant separation is achieved entirely through **MySQL grants**.

```
                         ┌───────────────────────────┐
   site: lv.cijagani.in ─┤ database: cijagani-lv     │
   user: cijagani-lv     │   └ db_user: lv_user (rw) │
                         ├───────────────────────────┤   one shared
   site: demo.cijagani.in┤ database: sofy-crm        ├── Percona 8.4
   user: cijagani-demo   │ database: pass-the-code   │   127.0.0.1:3306
                         │ database: passthecode     │
                         └───────────────────────────┘
```

Note from the live data: **a site may own several databases** (`demo.cijagani.in` owns three),
but a database belongs to exactly one site. `database.name` is globally UNIQUE.

This is a genuine limitation to understand: filesystem and PHP isolation between tenants is
strong (see the companion doc §4), but **database isolation is only as good as the grants**.
There is no per-tenant `mysqld`, no resource governor, and every database user is created
with host `'%'`.

### 1.2 Data model

```sql
database_server (
  id, is_active, is_default,
  engine,          -- "MySQL" | "MariaDB"  (auto-detected from version string)
  version,         -- e.g. "8.4"
  host, port,      -- 127.0.0.1 : 3306
  user_name,       -- "root"
  password CLOB,   -- ENCRYPTED (see 1.3)
  certificate CLOB -- optional CA cert for TLS connections
);
CREATE UNIQUE INDEX host_user_name_idx ON database_server (host, user_name);

database (
  id, site_id -> site(id) ON DELETE CASCADE,
  database_server_id -> database_server(id) ON DELETE CASCADE,
  name UNIQUE
);

database_user (
  id, database_id -> database(id) ON DELETE CASCADE,
  user_name UNIQUE,
  password CLOB,   -- ENCRYPTED
  permissions      -- "rw" | "ro"
);
```

**Multi-server support is real.** `database_server` is a table, not a config value — you can
register remote MySQL servers (with an optional CA certificate for TLS), and each database
points at one. `getActiveDatabaseServer()` picks the active/default one for new databases.

Live row on this host:

```
id | engine | version | host      | port | user_name | password
1  | MySQL  | 8.4     | 127.0.0.1 | 3306 | root      | def50200de3d3048f4e99abc...
```

### 1.3 Credential encryption

Passwords are **not** hashed — they must be recoverable to connect to MySQL. They are
**encrypted** with `defuse/php-encryption` (the `def50200` prefix is that library's header
format), through `App\Service\Crypto`:

```php
$databaseUserEntity->setPassword(Crypto::encrypt($databaseUserPassword));
// and on use:
$databaseServerEntity->getDecryptedPassword()
```

The encryption key derives from the application secret in
`/home/clp/htdocs/app/files/.env`. **Consequence: `db.sq3` alone is not enough to recover
credentials — you need `.env` too.** Both live under `/home/clp/`, so a backup of that
directory captures both. Conversely, restoring `db.sq3` onto a fresh install with a different
`APP_SECRET` will leave every stored password undecryptable.

### 1.4 The connection layer

`src/Database/Connection.php` — Doctrine DBAL over the PDO MySQL driver:

```php
$params = [
  "host"     => $server->getHost(),
  "port"     => $server->getPort(),
  "user"     => $server->getUserName(),
  "password" => $server->getDecryptedPassword(),
];
$driverOptions = [
  PDO::ATTR_TIMEOUT => $timeout,          // default 10s
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
];

// If the server row carries a CA certificate:
$tmpCertificateFile = tempnam(sys_get_temp_dir(), "clp-tmp-certificate-");
file_put_contents($tmpCertificateFile, $certificate);
$driverOptions[PDO::MYSQL_ATTR_SSL_CA] = $tmpCertificateFile;
$driverOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;   // ⚠
// ... unlinked in finally{}
```

Engine detection is by string match on the version variable:

```php
$engine = str_contains(strtolower($version), "maria") ? "MariaDB" : "MySQL";
```

> ⚠️ `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false` — when connecting to a *remote* database
> server over TLS, the server certificate is not verified against the hostname. The CA is
> checked, the identity is not. Same anti-pattern as the ACME client in the companion doc.

### 1.5 Creating a database — exact SQL

```bash
clpctl db:add --domainName=example.com --databaseName=my-database \
              --databaseUserName=john --databaseUserPassword='!secret!'
```

Flow (`DatabaseAddCommand` → `Database\Manager` → `Database\Connection`):

```
1. resolve site by domainName          (fails if the site does not exist)
2. activeDatabaseServer = getActiveDatabaseServer()
3. create database entity, validate
4. create database_user entity:
       permissions = PERMISSIONS_READ_WRITE ("rw")     ← ALWAYS rw from the CLI
       password    = Crypto::encrypt($plaintext)
5. Manager->createDatabase()   -> schemaManager->createDatabase("`name`")
6. Manager->createUser()       -> the GRANT sequence below
```

**`createDatabase`** is idempotent — it calls `hasDatabase()` (via `SHOW DATABASES`) first
and does nothing if the name already exists.

**`createUser`** first calls `deleteUser()` (a `DROP USER IF EXISTS`), making it idempotent
too, then issues:

```sql
-- MySQL:
CREATE USER '<user>'@'%' IDENTIFIED WITH mysql_native_password BY '<password>';
-- MariaDB:
CREATE USER '<user>'@'%' IDENTIFIED BY '<password>';

GRANT USAGE ON *.* TO '<user>'@'%';
```

then, for **read-write** (`rw`):

```sql
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER,
      CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW, SHOW VIEW,
      CREATE ROUTINE, ALTER ROUTINE, EVENT, TRIGGER
  ON `<database>`.* TO '<user>'@'%';

ALTER USER `<user>`@`%` REQUIRE NONE
  WITH MAX_QUERIES_PER_HOUR 0 MAX_CONNECTIONS_PER_HOUR 0
       MAX_UPDATES_PER_HOUR 0 MAX_USER_CONNECTIONS 0;
```

or, for **read-only** (`ro`):

```sql
GRANT SELECT ON `<database>`.* TO '<user>'@'%';
```

and finally:

```sql
FLUSH PRIVILEGES;
```

Three things worth flagging:

1. **Host is `'%'`, not `'localhost'`.** Every database user is created able to connect from
   any host. On this server MySQL is only reachable on `127.0.0.1` and ufw does not expose
   3306, so it is contained — but the grant itself is permissive, and it becomes a real
   exposure the moment `bind-address` or the firewall changes.
2. **`mysql_native_password`** is forced on MySQL. On MySQL 8.4 that plugin is deprecated and
   no longer enabled by default; on Percona 8.4 it still works but is on its way out. Expect
   friction on future upgrades.
3. **`ALTER USER ... REQUIRE NONE`** explicitly disables any TLS requirement for that user,
   and zeroes all resource limits (unlimited queries/connections per hour). Deliberate, but
   it means you cannot use MySQL's own rate limiting to contain a noisy tenant unless you
   re-apply limits yourself.

### 1.6 Deleting a database

`Manager::deleteDatabase($entity, $withUsers = true)`:

```
1. Connection->deleteDatabase()      -> schemaManager->dropDatabase("`name`")
                                        (guarded by hasDatabase())
2. if withUsers:
     foreach user: DROP USER IF EXISTS `<user>`;  FLUSH PRIVILEGES;
3. deleteDatabaseBackups()
     rm -rf /home/<siteUser>/backups/databases/<databaseName>/
4. DB rows removed by ON DELETE CASCADE
```

Note **step 3**: dropping a database also **destroys its local backups**. There is no
"delete the database but keep the dumps" path. If you need the dumps, copy them out first.

Site deletion calls this for every database the site owns (companion doc §13, step 10).

### 1.7 Export (`db:export`)

`Database\Exporter` → `CreateDatabaseDumpCommand`. The generated shell command:

```bash
[sudo | sudo -u <runAsUser>] /usr/bin/mysqldump \
  --force --opt --single-transaction --quick \
  -h<host> -P<port> -u<user> -p< /tmp/.clp_tmp_<sha1> \
  <databaseName> \
  [| sudo /bin/gzip] \
  | sudo /usr/bin/tee <outputFile> > /dev/null
```

Mechanics:

- **Password handling:** the decrypted password is written to `/tmp/.clp_tmp_<sha1(uniqid)>`,
  `chmod 0400`, and fed to mysqldump via `-p< <file>` — the shell redirects the file to
  stdin and mysqldump reads the password from there. This keeps the password out of
  `/proc/*/cmdline`. The temp file is `@unlink`ed in the object's `__destruct()`.
- **Compression is inferred from the filename** — if it ends in `.gz`, a `gzip` stage is
  inserted. Nothing else selects it.
- `--single-transaction` gives a consistent snapshot for InnoDB **without locking** — correct
  for a live site. Note it does *not* protect MyISAM tables.
- `--force` continues past SQL errors. Convenient, but it means a dump can complete
  "successfully" while having skipped broken tables.
- **Timeout: 7200s (2 hours).**
- `isSuccessful()` returns a hardcoded `true` — the command never reports failure.

```bash
clpctl db:export --databaseName=my-database \
                 --file=/home/myuser/backups/databases/my-database.sql.gz
```

### 1.8 Import (`db:import`)

`Database\Importer` → `ImportDatabaseDumpCommand`. This one is unusual:

```bash
# 1. write credentials file: [client]\npassword=<decrypted>   chmod 0400
# 2. then:
sudo /usr/bin/setfacl -m u:www-data:--- /usr/bin/sh
sudo chown www-data:www-data /tmp/.clp_tmp_<sha1>
sudo /bin/bash -c "/usr/bin/cat <dumpFile>" \
  | sudo -u www-data /bin/bash -c \
      "/usr/bin/mysql --defaults-extra-file=/tmp/.clp_tmp_<sha1> \
         -f -h<host> -u<user> -P<port> <databaseName>"
```

For a gzipped dump the first half becomes `sudo /bin/bash -c "sudo /usr/bin/gunzip < <file>"`.

What is going on here:

- The password goes in a `--defaults-extra-file` (`[client] password=...`) rather than the
  command line — again keeping it out of the process table.
- **`setfacl -m u:www-data:--- /usr/bin/sh`** revokes `www-data`'s access to `/usr/bin/sh`
  before dropping privileges to `www-data` to run the client. This is a hardening measure
  against a malicious dump file achieving shell execution through MySQL. It is a global,
  persistent ACL change on `/usr/bin/sh` — a side effect on the whole system, not scoped to
  this operation.
- `-f` (force) continues past errors, same caveat as the export.
- Cleanup in `__destruct()`: `sudo -u www-data /usr/bin/rm -f <tmpFile>`.
- **Timeout: 7200s.**

```bash
clpctl db:import --databaseName=my-database --file=/path/dump.sql.gz
```

### 1.9 phpMyAdmin

Served by the **panel's** nginx instance, not the site nginx:

```
/home/clp/htdocs/app/files/public/phpmyadmin
  → https://<panel-host>:8443/phpmyadmin
```

From the panel vhost (`/home/clp/services/nginx/sites-enabled/cloudpanel.conf`):

```nginx
if ($request_uri ~ ^/phpmyadmin) {
  set $basicAuth "off";        # ← basic auth is DISABLED for phpMyAdmin
}
rewrite ^/phpmyadmin$ /phpmyadmin/$1 permanent;
```

Worth noting: if you enable panel basic auth via `clpctl cloudpanel:enable-basic-auth`,
**phpMyAdmin is explicitly exempted from it**. It then relies solely on MySQL credentials for
protection. Combined with port 8443 being open to the world in ufw on this host, that is
worth tightening.

`clpctl db:show:master-credentials` prints the decrypted root credentials for the active
server.

### 1.10 MySQL configuration on this host

```
Percona Server 8.4.10-10  (GPL), Revision d76e81f4
/etc/mysql/my.cnf
/etc/mysql/conf.d/mysql.cnf
/etc/mysql/mysql.conf.d/mysqld.cnf
```

**[LOCAL]** `/etc/logrotate.d/mysql-slow-corbital` — a custom slow-query-log rotation
(weekly, keep 4, compress, `mysqladmin flush-logs` in postrotate). Not stock CloudPanel.

---

## 2. Backup Management

### 2.1 There are three separate backup systems

This is the single most important thing to understand, and the reason the audit at the top of
this document found gaps. CloudPanel has **three independent backup mechanisms** that cover
different things, are configured differently, and are scheduled differently:

| # | System | Covers | Trigger | Destination | Retention |
|---|---|---|---|---|---|
| **A** | **Panel self-backup** | The panel app + `db.sq3` | `create_backup.sh`, run on **update** | `/home/clp/backups/` | keep **3** |
| **B** | **Local DB backup** | MySQL dumps, per site | `clpctl db:backup` — **you must schedule it** | `/home/<user>/backups/databases/` | **7 days** default |
| **C** | **Remote backup** | Site homes + vhosts + (runs B first) | `clpctl remote-backup:create` via `/etc/cron.d/clp-rclone` | rclone remote | configurable days |

**None of them is enabled by default.** A is only invoked during upgrades. B and C require
explicit setup. This is why a stock CloudPanel box can run for a year with zero backups —
exactly the state this server is in.

```
   ┌──────────────── C: remote-backup:create ─────────────────┐
   │                                                          │
   │  step 1 ──► calls B (db:backup) internally               │
   │  step 2 ──► per site: tar(home + vhost) ─► rclone ─► ☁  │
   │  step 3 ──► purge remote dirs older than retention       │
   └──────────────────────────────────────────────────────────┘
                              ▲
                              │ C invokes B, but B never invokes C
                              │
   ┌──────────────── B: db:backup ────────────────────────────┐
   │  per database ──► mysqldump ─► /home/<user>/backups/     │
   └──────────────────────────────────────────────────────────┘

   ┌──────────────── A: create_backup.sh ─────────────────────┐
   │  panel app + db.sq3 ─► /home/clp/backups/   (on upgrade) │
   └──────────────────────────────────────────────────────────┘
```

Note the asymmetry: **running C gives you B for free, but running B gives you nothing
off-server.** If you configure only one thing, configure C.

---

### 2.2 System A — Panel self-backup

`/home/clp/scripts/create_backup.sh`, verbatim:

```bash
#!/bin/bash
BACKUPS_DIRECTORY="/home/clp/backups/"
NOW=$(date '+%Y-%m-%d_%H-%M-%S')
BACKUP_DIRECTORY="$(realpath -s $BACKUPS_DIRECTORY)/$NOW/"
if [ ! -d $BACKUP_DIRECTORY ]; then
  APP_DIRECTORY="$(realpath -s $BACKUP_DIRECTORY)/app/"
  mkdir -p $APP_DIRECTORY
  APP_DATA_DIRECTORY="$(realpath -s $APP_DIRECTORY)/data/"
  mkdir $APP_DATA_DIRECTORY
  echo "" > /home/clp/htdocs/app/files/var/log/prod.dev
  cp -R /home/clp/htdocs/app/files/ $APP_DIRECTORY
  sqlite3 /home/clp/htdocs/app/data/db.sq3 ".backup $(realpath -s $APP_DATA_DIRECTORY)/db.sq3"
fi
# Keep 3 backups
cd $BACKUPS_DIRECTORY && ls -t | tail -n +4 | xargs rm -rf
```

Produces:

```
/home/clp/backups/<Y-m-d_H-M-S>/
└── app/
    ├── files/          # full copy of the Symfony app (including .env)
    └── data/db.sq3     # SQLite online backup
```

Good detail: it uses **`sqlite3 .backup`**, not `cp`. That is SQLite's online backup API, so
the copy is transactionally consistent even while the panel is writing. Copying a live
`.sq3` with `cp` can produce a corrupt file; this does not.

Retention is `ls -t | tail -n +4 | xargs rm -rf` — keep the 3 newest, delete the rest.

**What this does and does not cover:** it captures the panel's configuration (every site
definition, every certificate private key, every encrypted credential) and the `.env` needed
to decrypt them. It captures **none of your website files and none of your MySQL data.**

Live state here — three directories, dated to CloudPanel updates rather than a schedule:

```
2026-02-17_04-15-01/    2026-02-18_04-15-01/    2026-07-14_00-17-15/
manual-rename-20260805T111808Z/     ← [LOCAL] hand-made, holds .bak copies of
                                       dotenv, db.sq3, a vhost, a pool conf, cert+key
```

---

### 2.3 System B — Local database backups (`db:backup`)

```bash
clpctl db:backup --ignoreDatabases='db1,db2' --retentionPeriod=7
```

`DatabaseBackupCommand`, `RETENTION_PERIOD = 7` by default.

**Flow:**

```
for each site:
  for each database of that site:
     if databaseName not in ignoreDatabases:
        createDatabaseDump()
        resetPermissions()
        cleanUpBackups()
```

**Output path** (`$dateTime` is UTC):

```
/home/<siteUser>/backups/databases/<databaseName>/<Y-m-d>/<databaseName>_<unixTimestamp>.sql.gz
```

e.g. `/home/cijagani-lv/backups/databases/cijagani-lv/2026-09-06/cijagani-lv_1757145600.sql.gz`

The `.gz` suffix is what triggers compression in the exporter (§1.7). The directory is
created first via `createOutputDirectory()`.

**Permissions reset after each dump:**

```
chown -R <siteUser>:<siteUser>  /home/<siteUser>/backups/databases/
find ... -type d -exec chmod 750
find ... -type f -exec chmod 760      # ⚠ 760 = rwxrw---- on a .sql.gz
```

> `760` on a dump file is odd — it grants execute to the owner on data files, and is
> inconsistent with the `770`/`600` scheme used elsewhere. Harmless in practice, but if you
> reimplement this use `640`.

**Retention / cleanup** (`DeleteOldFilesRecursiveCommand`):

```bash
sudo /usr/bin/find /home/<siteUser>/backups/databases/ \
     -mindepth 1 -type d -mtime +<retentionPeriod> -exec rm -rf {} \; > /dev/null 2>&1
```

Two things to notice:

- It deletes **directories** by mtime, not files. Because dumps are grouped into `<Y-m-d>/`
  directories, deleting a whole day's directory is the intended unit.
- `-mindepth 1` protects the `databases/` root itself, but the glob will also match the
  per-database name directories (`<databaseName>/`) once *they* are older than the retention
  period by mtime. In practice their mtime updates whenever a new dated subdirectory is
  created inside them, so an actively-backed-up database is safe; one that stops being
  backed up will eventually have its whole tree removed.
- Errors are discarded (`> /dev/null 2>&1`) and the command's `isSuccessful()` is hardcoded
  `true`. **Failures here are silent.**

**Crucially: `db:backup` is not scheduled by CloudPanel.** No cron file is created for it.
Either you add one yourself, or you enable remote backups (System C), which calls it.

---

### 2.4 System C — Remote backups (`remote-backup:create`)

The full solution: dumps databases, tars every site home, ships it off-server with rclone,
then prunes old remote copies.

```bash
clpctl remote-backup:create [--delay=true]
```

#### 2.4.1 Gating

```php
$isEnabled       = (bool) getConfigValue("remote_backup_enabled");
$storageProvider =        getConfigValue("remote_backup_storage_provider");
if ($isEnabled && $storageProvider) { ... }   // otherwise: silent no-op, exit SUCCESS
```

If remote backup is not configured, the command **exits successfully having done nothing** —
which is exactly why an unconfigured install looks healthy. Do not treat exit code 0 from
this command as proof a backup happened.

#### 2.4.2 Storage providers

`Backup\StorageProvider`:

```php
AMAZON_S3            = "amazon-s3"
GOOGLE_DRIVE         = "google-drive"
DIGITAL_OCEAN_SPACES = "digital-ocean-spaces"
DROPBOX              = "dropbox"
SFTP                 = "sftp"
WASABI               = "wasabi"
CUSTOM_RCLONE        = "custom-rclone"
```

Everything runs through **rclone**; the providers are just config templates. Example
(`AmazonS3ConfigTemplate`):

```ini
[remote]
type = s3
provider = AWS
env_auth = true
acl = bucket-owner-full-control
storage_class = STANDARD
region = <region>
access_key_id = <key>
secret_access_key = <secret>
```

`SftpConfigTemplate`:

```ini
[remote]
type = sftp
shell_type = unix
disable_hashcheck = true
```

`ConfigBuilder` always names the remote `[remote]` — which is why every rclone command
targets `remote:<path>`.

#### 2.4.3 Rclone file layout

```
/root/.config/rclone/rclone.conf              # the remote definition
/home/clp/.config/rclone/credentials/         # provider credential files (chown clp:clp,
                                              #  chmod 770 recursively)
/etc/cron.d/clp-rclone                        # the schedule
```

#### 2.4.4 Execution flow

```
1. backupDatabases()
     → internally runs the `db:backup` command (System B)
       so dumps land in /home/<user>/backups/databases/ FIRST,
       and are therefore INSIDE the tar created in step 3.

2. [Dropbox only] refreshDropboxAccessToken($delay)
       --delay=true staggers the refresh; used by the cron invocation.

3. for each site (unless excluded):
     excludes = [".ssh", "tmp", "logs"] + per-site excludes from config

     write /home/<user>/site-settings.json     ← restore metadata, see below
     write /home/<user>/site-vhost             ← copy of the rendered vhost

     sources = [ /home/<user>/ , /etc/nginx/sites-enabled/<domain>.conf ]

     tar:    sudo /bin/tar cfv /home/<user>/tmp/backup.tar \
                  --exclude=<each> <sources> --warning=no-file-changed
     rclone: sudo /usr/bin/rclone -v copy /home/<user>/tmp/backup.tar \
                  remote:<destination> [--config=...] [--drive-impersonate=<email>]

     finally: delete backup.tar, site-settings.json, site-vhost

4. cleanBackups()   — remote retention, see 2.4.7
```

Note the ordering subtlety: databases are dumped **before** the tar runs, so the SQL dumps
ride along inside each site's archive. A single restored `backup.tar` therefore contains both
the files and the data for that site. That is a good design and worth copying.

Also note `--exclude=logs` — **logs are deliberately not backed up**, along with `tmp` and
`.ssh`.

#### 2.4.5 The sidecar metadata files

Before tarring, two files are written into the site home so they end up inside the archive:

**`/home/<user>/site-settings.json`** — everything needed to recreate the site:

```json
{
  "type": "php",
  "domainName": "example.com",
  "rootDirectory": "example.com/public",
  "siteUser": "myuser",
  "pageSpeed": null,
  "phpSettings": {
    "version": "8.3",
    "memoryLimit": "512M",
    "maxExecutionTime": "60",
    "maxInputTime": "60",
    "maxInputVars": "10000",
    "postMaxSize": "64M",
    "uploadMaxFileSize": "64M",
    "additionalConfiguration": "..."
  }
}
```

(For Node.js sites: `nodejsSettings {version, port}`. For Python: `pythonSettings`.)

**`/home/<user>/site-vhost`** — a copy of the site's rendered nginx vhost.

Together these make an archive **self-describing**: you can rebuild the site on a bare server
from the tar alone, without the panel database. This is the single best idea in CloudPanel's
backup design.

#### 2.4.6 Remote destination path

```php
$destination = "<bucket|space>/<storageDirectory>/<Y-m-d>/<H.i>/home/<siteUser>/"
```

- `<bucket>` for S3/Wasabi, `<space>` for DigitalOcean Spaces, empty for Dropbox/GDrive/SFTP
- `<Y-m-d>` and `<H.i>` come from the **server timezone** (`config.timezone`), not UTC
- Dropbox strips the leading `/`

So a backup run produces:

```
mybucket/cloudpanel/2026-09-06/04.15/home/cijagani-lv/backup.tar
mybucket/cloudpanel/2026-09-06/04.15/home/cijagani-demo/backup.tar
...
```

One dated directory per run, one tar per site inside it.

#### 2.4.7 Remote retention

```php
$retentionPeriod  = (int) getConfigValue("remote_backup_retention_period");   // days
$storageDirectory =       getConfigValue("remote_backup_storage_directory");

$deleteDateTime = now() - retentionPeriod days;  → UTC, time set to 00:00:00

directories = rclone lsjson <remotePath> --dirs-only
foreach directory:
    try { $directoryDate = new DateTime($directory["Name"]); }   // "2026-09-06" parses
    catch { skip }                                               // anything else is skipped
    if ($directoryDate <= $deleteDateTime):
        rclone purge <remotePath>/<directoryName>/
```

The retention key is the **directory name being parseable as a date**. Anything you drop into
that remote path that does not look like a date is ignored and kept forever — convenient, but
also means a malformed date directory silently escapes cleanup.

#### 2.4.8 Timeouts

| Operation | Timeout |
|---|---|
| `tar` create | 21600s (6h) |
| `rclone copy` | 21600s (6h) |
| `rclone purge` | 21600s (6h) |
| `rclone lsjson` | 20s |
| `mysqldump` / `mysql` import | 7200s (2h) |
| delete temp files | 60s |
| old-backup cleanup `find` | 360s |

#### 2.4.9 The schedule

`Rclone::createCronJob()` writes `/etc/cron.d/clp-rclone`:

```cron
# daily at a chosen hour:
MAILTO=""
0 <executionTime> * * * clp /usr/bin/bash -c "/usr/bin/clpctl remote-backup:create --delay=true" &> /dev/null

# or every N hours (N ∈ {3, 6, 12}):
MAILTO=""
0 */<N> * * * clp /usr/bin/bash -c "/usr/bin/clpctl remote-backup:create --delay=true" &> /dev/null
```

`Backup\Frequency`: `DAILY = "daily"`, `EVERY_THREE_HOURS = 3`, `EVERY_SIX_HOURS = 6`,
`EVERY_TWELVE_HOURS = 12`.

Runs as the **`clp`** user (which has `NOPASSWD: ALL` in sudoers), and **all output is
discarded** (`&> /dev/null`, `MAILTO=""`). Combined with the silent-no-op gating in §2.4.1
and the hardcoded `isSuccessful() => true` in the command classes, this means:

> **A failing remote backup is completely invisible.** No mail, no log, no non-zero exit.
> Failures only surface as `Notification` rows in the panel database — `addNotification()`
> is called per-site on exception with subject `"Remote Backup failed: <domain>"`.

Monitor the notifications, or better, verify the remote side independently.

#### 2.4.10 Failure isolation

Each site is backed up inside its own try/catch. One site failing does not abort the run —
it records a notification and the loop continues. Correct behaviour, worth copying.

---

### 2.5 Cloud provider snapshots

Separate from all of the above, CloudPanel can trigger whole-VM snapshots through provider
APIs:

```
clpctl aws:image:create
clpctl do:snapshot:create
clpctl gce:snapshot:create
clpctl hetzner:snapshot:create
clpctl vultr:snapshot:create
```

Implemented in `src/Aws/`, `src/Do/`, `src/Gce/`, `src/Hetzner/`, `src/Vultr/`. These are
full-disk images — the coarsest but most complete recovery option, and the only one that
captures the panel, sites, databases and system config in a single consistent artifact.

### 2.6 What is *not* backed up by any system

Worth being explicit, because the gaps matter:

| Not covered | By which system | Notes |
|---|---|---|
| `logs/`, `tmp/`, `.ssh/` in site homes | excluded from C | deliberate |
| `/etc/php/*/fpm/pool.d/*.conf` | none | pool tuning is **not** in any backup |
| `/etc/logrotate.d/<user>` | none | regenerable |
| `/etc/cron.d/<user>` | none | but `cron_job` rows are in `db.sq3` |
| `/etc/nginx/nginx.conf`, `conf.d/` | none | your **[LOCAL]** customisations are unprotected |
| `/etc/nginx/ssl-certificates/` | none directly | but certs live in `db.sq3` |
| MySQL server config | none | |
| ufw rules | none | `firewall_rule` table only |

The per-site vhost *is* captured (as `site-vhost` and in the tar sources), but **global nginx
config is not**. On this server that means the `conf.d/` rate-limit and bad-bot files, and
all the `nginx.conf` tuning, exist in exactly one place.

---

## 3. Cron Management

### 3.1 Two independent layers

```
┌─ LAYER 1: per-site user cron ────────────────────────────────────────┐
│  DB table `cron_job`  ──generated──►  /etc/cron.d/<siteUser>         │
│  Managed through the panel UI / API. Runs as the site user.          │
└──────────────────────────────────────────────────────────────────────┘

┌─ LAYER 2: platform schedules ────────────────────────────────────────┐
│  /etc/cron.d/clp-rclone     → remote-backup:create   (written by     │
│                                Rclone::createCronJob)                │
│  clp-agent internal Go scheduler → LE renewal, monitoring collection │
│                                    (github.com/prprprus/scheduler)   │
└──────────────────────────────────────────────────────────────────────┘
```

There is **no user crontab involvement at all** — `/var/spool/cron/crontabs/` is empty on
this host. Everything is file-based in `/etc/cron.d/`.

### 3.2 Data model

```sql
cron_job (
  id,
  site_id -> site(id) ON DELETE CASCADE,
  minute VARCHAR(16), hour VARCHAR(16), day VARCHAR(16),
  month VARCHAR(16),  weekday VARCHAR(16),
  command VARCHAR(255),
  created_at, updated_at
);
CREATE INDEX IDX_8E6EB8EF6BD1646 ON cron_job (site_id);
```

Five separate schedule columns rather than one expression string — that is what lets the UI
present five dropdowns. **`command` is capped at 255 characters**, which is a real constraint
for long deploy one-liners.

### 3.3 Generated file format

`Updater::updateUserCrontab()` writes `/etc/cron.d/<siteUser>` (root:root 0644):

```cron
MAILTO=""
<minute> <hour> <day> <month> <weekday> <siteUser> <command>
```

The critical difference from a user crontab: **`/etc/cron.d` files require a user field**
between the schedule and the command. That sixth column is what makes the job run as the site
user rather than root. Get it wrong and cron silently refuses the file.

`MAILTO=""` suppresses mail, which also means **cron job output and errors are discarded**
unless the command redirects them somewhere.

All of a site's `cron_job` rows are written into that one file; the file is rewritten
wholesale on every change.

### 3.4 Lifecycle

| Event | Action |
|---|---|
| Add/edit/delete a cron job | rewrite `/etc/cron.d/<siteUser>` from all rows for that site |
| Delete the site | `rm /etc/cron.d/<siteUser>` **and** `crontab -r -u <siteUser>` |

The `DeleteCrontabCommand` (`crontab -r`) is belt-and-braces — it clears any user crontab the
tenant may have created by hand via `crontab -e`, which CloudPanel does not manage but cannot
leave behind either. Note the implication: **a site user *can* create their own crontab
entries** outside the panel, and those are invisible to the UI until the site is deleted.

### 3.5 Live cron jobs on this server

Ten site cron files exist. All 10 `cron_job` rows are Laravel-style schedulers or HTTP pings:

```cron
# /etc/cron.d/cijagani-main
MAILTO=""
* * * * * cijagani-main /usr/bin/php8.4 /home/cijagani-main/htdocs/cijagani.in/whatsmark.io/artisan schedule:run >> /dev/null 2>&1

# /etc/cron.d/cijagani-help
MAILTO=""
0 */3 * * * cijagani-help /usr/bin/php8.3 /home/cijagani-help/htdocs/help.cijagani.in/artisan schedule:run

# /etc/cron.d/cijagani-perfexmodules
MAILTO=""
*/5 * * * * cijagani-perfexmodules wget -q -O- https://perfexmodules.cijagani.in/cron/index

# /etc/cron.d/cijagani-demo
MAILTO=""
* * * * * cijagani-demo cd /home/cijagani-demo/htdocs/demo.cijagani.in/instamark && php artisan schedule:run >> /dev/null 2>&1
```

Plus the system ones: `anacron`, `e2scrub_all`, `php` (session GC every 30 min), `sysstat`.

### 3.6 ⚠️ The PHP version trap in cron

Covered in the companion doc §9, but it bites hardest here, so it is worth repeating with the
live evidence.

`/usr/bin/php` is a **server-wide** `update-alternatives` symlink — currently **8.4**. It has
nothing to do with the site's PHP-FPM version. Sorting this server's cron jobs by how they
invoke PHP:

| Cron file | Invocation | Site's FPM version | Actually runs on |
|---|---|---|---|
| `cijagani-main` | `/usr/bin/php8.4` | 8.4 | 8.4 ✅ pinned |
| `cijagani-help` | `/usr/bin/php8.3` | 8.2 | 8.3 ⚠️ pinned to the *wrong* version |
| `cijagani-shopify-app` | `/usr/bin/php8.4` | 8.4 | 8.4 ✅ pinned |
| `cijagani-app` | `/usr/bin/php` | 8.4 | 8.4 — by luck |
| `cijagani-sales` | `'/usr/bin/php'` | 8.2 | **8.4** ❌ mismatch |
| `cijagani-whatsmark-saas` | `/usr/bin/php` | 8.4 | 8.4 — by luck |
| `cijagani-concordcrm-modules` | `/usr/bin/php` | ? | 8.4 |
| `corbitaltech-campaigns` | `/usr/bin/php -q` | 8.3 | **8.4** ❌ mismatch |
| `cijagani-demo` | bare `php` (via `cd &&`) | 8.4 | 8.4 — by luck |
| `cijagani-perfexmodules` | `wget` (HTTP) | 8.3 | **8.3** ✅ — goes through nginx→FPM |

Three sites are running their scheduler on a **different PHP version than their web
requests**. `cijagani-help` is pinned, but to 8.3 while its FPM pool is 8.2.

The failure mode is nasty because it is partial: the code usually runs, but extension
availability, `php.ini` values (remember per-site ini comes from `PHP_VALUE` on the **FastCGI
request** and does not apply to CLI at all — companion doc §8.4), and language-version
behaviour all differ.

The `wget` pattern used by `cijagani-perfexmodules` sidesteps the whole problem by going
through nginx → FPM, which guarantees the site's real PHP version and ini settings. It costs
an HTTP round trip and needs the endpoint to be safe to expose, but it is correct by
construction.

**Rules to adopt:**

1. Always pin the binary: `/usr/bin/php8.3 /path/artisan schedule:run`
2. Verify against the site's actual pool: `grep -l <user> /etc/php/*/fpm/pool.d/*.conf`
3. Or give each site user a `~/bin/php` symlink (companion doc §16.6) and use bare `php`
4. Never rely on `/usr/bin/php` staying on the version you tested against — a
   `update-alternatives` change or a PHP package install silently moves it

### 3.7 Logging: cron jobs are silent by default

`MAILTO=""` plus the common `>> /dev/null 2>&1` means **failures produce nothing anywhere**.
Only two of the ten jobs on this server would show output at all, and none writes to a file.

For anything you care about, redirect to a real log inside the site's log directory (which is
already rotated by the per-site logrotate rule — companion doc §11.2):

```cron
* * * * * myuser /usr/bin/php8.3 /home/myuser/htdocs/example.com/artisan schedule:run >> /home/myuser/logs/cron.log 2>&1
```

`/home/<user>/logs/*/*.log` is the logrotate glob, so a file at `logs/cron.log` (one level up)
is **not** matched — put it in a subdirectory, e.g. `logs/cron/cron.log`, to get rotation for
free.

### 3.8 The clp-agent scheduler

`/usr/sbin/clp-agent` — a Go binary running as root, using
`github.com/prprprus/scheduler v0.5.0`. It owns the schedules that have **no cron file**:

- Let's Encrypt certificate renewal
- Instance monitoring collection (`instance_cpu`, `instance_memory`,
  `instance_disk_usage`, `instance_load_average` tables)
- Monitoring data cleanup (`MonitoringDataCleanCommand`)
- Announcement checks

The binary embeds a direct reference to `/home/clp/htdocs/app/data/db.sq3`.

**If you are looking for why something runs on a schedule and cannot find a cron entry, it is
in here.** There is no configuration file for these intervals; they are compiled in.

---

## 4. Live State of This Server

A consolidated audit, current as of 2026-09-06.

### 4.1 Panel

```
app_version         2.5.4
release_channel     stable
instance_uid        836b167a7d073896
timezone            Asia/Kolkata          ← backup path timestamps use THIS, not UTC
custom_domain       manage.cijagani.in
masquerade_address  59.95.102.155
```

### 4.2 Database layer

```
Engine        Percona Server 8.4.10-10 (MySQL 8.4 compatible)
Servers       1  (id=1, MySQL 8.4, 127.0.0.1:3306, user root, password encrypted)
Databases     ~20, all on the local server
DB users      all permissions = "rw"   (no read-only users defined)
Grant host    '%'  for every user
Auth plugin   mysql_native_password (deprecated in MySQL 8.4)
```

Sites owning multiple databases: `demo.cijagani.in` → `sofy-crm`, `pass-the-code`,
`passthecode`.

### 4.3 Backup posture — the gaps

| System | Configured? | Evidence |
|---|---|---|
| A — Panel self-backup | Partial | 3 dirs in `/home/clp/backups/`, dated to updates only |
| B — Local DB backup | ❌ **No** | Every `/home/*/backups/databases/` holds only `.gitignore` |
| C — Remote backup | ❌ **No** | No `remote_backup_*` config keys; no `/etc/cron.d/clp-rclone` |
| Cloud snapshots | Unknown | Not visible from inside the VM |

`rclone` **is** installed (`/usr/bin/rclone`) but has no config directory — so the remote
backup feature was never set up, not merely disabled.

> **There are currently no database backups on this server.** If MySQL data were lost, there
> would be nothing to restore from short of a hypervisor-level snapshot.

### 4.4 Cron

```
Site cron files      10  (/etc/cron.d/<siteUser>)
cron_job rows        10
System cron files    anacron, e2scrub_all, php, sysstat
clp-rclone           absent
PHP version pinning  3 of 9 PHP jobs run on the wrong version (see §3.6)
```

### 4.5 [LOCAL] customisations found

- `/etc/logrotate.d/mysql-slow-corbital` — custom slow-log rotation
- `/home/clp/backups/manual-rename-20260805T111808Z/` — hand-made `.bak` copies of
  `dotenv`, `db.sq3`, a vhost, a pool conf, and a cert/key pair

---

## 5. Operational Recipes

### 5.1 Minimum viable backup for this server

Two steps close the gap identified above.

**Step 1 — schedule local database dumps immediately** (works today, no external account):

```bash
sudo tee /etc/cron.d/clp-db-backup >/dev/null <<'EOF'
MAILTO=""
30 2 * * * clp /usr/bin/bash -c "/usr/bin/clpctl db:backup --retentionPeriod=14" >> /var/log/clp-db-backup.log 2>&1
EOF
sudo chmod 644 /etc/cron.d/clp-db-backup

# verify it works right now, before trusting the schedule:
sudo clpctl db:backup --retentionPeriod=14
find /home/*/backups/databases -name '*.sql.gz' -newermt '-10 minutes' -ls
```

Unlike the stock invocation, this one keeps a log — given that `db:backup` swallows its own
errors, an external log is the only signal you will get.

**Step 2 — configure remote backup** (panel → Admin Area → Backups, or set the
`remote_backup_*` config values). That gets you off-server copies *and* runs `db:backup`
automatically as its first step, making Step 1 redundant — but keep both until you have
verified a remote run actually lands files.

### 5.2 Verify a backup actually happened

Never trust exit code 0 from `remote-backup:create` (§2.4.1).

```bash
# local dumps present and fresh?
find /home/*/backups/databases -name '*.sql.gz' -mtime -1 -ls

# remote listing (uses the panel's own rclone remote)
sudo rclone --config /root/.config/rclone/rclone.conf lsd remote:<storageDirectory>/
sudo rclone --config /root/.config/rclone/rclone.conf ls  remote:<storageDirectory>/$(date +%F)/

# panel-side failure notifications
sqlite3 /home/clp/htdocs/app/data/db.sq3 \
  "SELECT created_at, subject FROM notification ORDER BY created_at DESC LIMIT 20;"
```

### 5.3 Restore a single database

```bash
# choose a dump
ls -la /home/myuser/backups/databases/my-database/

# import (handles .gz automatically)
sudo clpctl db:import --databaseName=my-database \
     --file=/home/myuser/backups/databases/my-database/2026-09-06/my-database_1757145600.sql.gz
```

Import uses `-f` (force), so it continues past errors — check the output rather than assuming
success. To restore into a *different* database, create it first with `clpctl db:add`.

### 5.4 Restore a whole site from a remote tar

The archive is self-describing (§2.4.5), so this works even on a fresh server:

```bash
# 1. fetch and unpack somewhere safe
sudo rclone copy remote:<dir>/<Y-m-d>/<H.i>/home/myuser/backup.tar /tmp/restore/
mkdir -p /tmp/restore/x && sudo tar xf /tmp/restore/backup.tar -C /tmp/restore/x

# 2. read the metadata to recreate the site with matching settings
cat /tmp/restore/x/home/myuser/site-settings.json      # type, domain, root, PHP version+ini
cat /tmp/restore/x/home/myuser/site-vhost              # the original nginx vhost

# 3. recreate the site shell with the SAME siteUser and PHP version
sudo clpctl site:add:php --domainName=example.com --phpVersion=8.3 \
     --vhostTemplate='Generic' --siteUser=myuser --siteUserPassword='...'

# 4. restore files over the new home
sudo rsync -a /tmp/restore/x/home/myuser/ /home/myuser/

# 5. recreate database + user, then import the dump that rode along in the tar
sudo clpctl db:add --domainName=example.com --databaseName=my-database \
     --databaseUserName=john --databaseUserPassword='...'
sudo clpctl db:import --databaseName=my-database \
     --file=/home/myuser/backups/databases/my-database/<date>/<dump>.sql.gz

# 6. fix ownership, re-apply the vhost if you customised it, reload
sudo clpctl system:permissions:reset
sudo nginx -t && sudo systemctl reload nginx
```

Remember `logs/`, `tmp/` and `.ssh/` were excluded from the archive — recreate
`.ssh/authorized_keys` if the site relied on key-based deploys.

### 5.5 Restore the panel itself

```bash
sudo systemctl stop clp-nginx clp-php-fpm clp-agent
sudo cp /home/clp/backups/<timestamp>/app/data/db.sq3 /home/clp/htdocs/app/data/db.sq3
sudo rsync -a /home/clp/backups/<timestamp>/app/files/ /home/clp/htdocs/app/files/
sudo chown -R clp:clp /home/clp/htdocs/app
sudo systemctl start clp-php-fpm clp-nginx clp-agent
```

⚠️ **The `.env` must match the `db.sq3`.** Encrypted passwords and certificate keys are
decrypted with the key derived from `APP_SECRET` (§1.3). Restoring a database against a
different `.env` leaves every credential unreadable. Restore both together, always.

### 5.6 Audit PHP versions used by cron

```bash
# what each cron job invokes
grep -h -o '/usr/bin/php[0-9.]*' /etc/cron.d/cijagani-* /etc/cron.d/corbitaltech-* 2>/dev/null | sort | uniq -c

# what each site's FPM pool actually is
sqlite3 /home/clp/htdocs/app/data/db.sq3 \
  "SELECT s.user, s.domain_name, p.php_version
     FROM site s JOIN php_settings p ON p.site_id = s.id ORDER BY s.user;"

# the global default that bare `php` resolves to
readlink -f /usr/bin/php
```

Cross-reference the two lists; any row where the pinned binary differs from the pool version
is a latent bug.

### 5.7 Add a cron job outside the panel

Prefer the UI so the job lives in `cron_job` and survives a restore. If you must do it by
hand, be aware the panel will **overwrite `/etc/cron.d/<siteUser>` wholesale** the next time
that site's cron is edited. Use a differently-named file:

```bash
sudo tee /etc/cron.d/myuser-custom >/dev/null <<'EOF'
MAILTO=""
*/10 * * * * myuser /usr/bin/php8.3 /home/myuser/htdocs/example.com/artisan queue:work --stop-when-empty >> /home/myuser/logs/cron/queue.log 2>&1
EOF
sudo mkdir -p /home/myuser/logs/cron && sudo chown myuser:myuser /home/myuser/logs/cron
```

Note this file is **not** removed when the site is deleted — only `/etc/cron.d/<siteUser>` is.

---

## 6. Blueprint: Building Your Own

Design guidance for reimplementing these three subsystems, with CloudPanel's mistakes fixed.

### 6.1 Database management

**Data model** — keep the shape, it is sound:

```sql
CREATE TABLE database_server (
  id INTEGER PRIMARY KEY,
  engine TEXT, version TEXT, host TEXT, port INTEGER,
  user_name TEXT, password_enc TEXT,      -- encrypted, not hashed
  ca_certificate TEXT,
  is_active BOOLEAN, is_default BOOLEAN,
  UNIQUE (host, user_name)
);
CREATE TABLE db (
  id INTEGER PRIMARY KEY,
  site_id INTEGER REFERENCES site(id) ON DELETE CASCADE,
  database_server_id INTEGER REFERENCES database_server(id),
  name TEXT UNIQUE
);
CREATE TABLE db_user (
  id INTEGER PRIMARY KEY,
  database_id INTEGER REFERENCES db(id) ON DELETE CASCADE,
  user_name TEXT UNIQUE, password_enc TEXT,
  permissions TEXT CHECK (permissions IN ('rw','ro'))
);
```

**Do this differently:**

| CloudPanel | Do instead |
|---|---|
| `CREATE USER '<u>'@'%'` | `'<u>'@'localhost'` for local sites; `'%'` only when a remote client genuinely needs it |
| `IDENTIFIED WITH mysql_native_password` | `caching_sha2_password` (the MySQL 8 default) |
| `REQUIRE NONE WITH MAX_* 0` | Leave TLS policy alone; set real `MAX_USER_CONNECTIONS` per tenant |
| `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false` | Verify the certificate |
| CLI always creates `rw` users | Expose the `ro` path — the grant logic already exists |
| Drop DB ⇒ silently deletes its dumps | Require an explicit `--delete-backups` flag |
| `isSuccessful() => true` everywhere | Actually check exit codes |

**Keep these:**

- Password in a `chmod 0400` temp file fed via stdin or `--defaults-extra-file`, never on the
  command line. Delete it in a `finally`/destructor.
- `--single-transaction --quick` for dumps.
- Idempotent create (`hasDatabase()` guard; `DROP USER IF EXISTS` before `CREATE USER`).
- Encrypt credentials with an authenticated construction (`defuse/php-encryption` or
  libsodium) keyed from an app secret held outside the database.

**Add:** per-tenant `MAX_USER_CONNECTIONS`, an explicit `ro` user alongside every `rw` user,
and a size-accounting query so you can show tenants their database footprint.

### 6.2 Backup management

**The single best idea to steal: self-describing archives.** Writing `site-settings.json` and
`site-vhost` into the home directory immediately before tarring means an archive can be
restored onto a bare machine with no control-plane database. Do this.

**The structure to copy:**

```
backup_run:
  1. dump all databases into each site's own backup directory
     → dumps end up INSIDE the site archive in step 3
  2. write sidecar metadata into each site home
  3. tar (site home + its vhost), excluding logs/tmp/.ssh
  4. ship each archive to object storage under <dir>/<Y-m-d>/<H.i>/home/<user>/
  5. prune remote directories older than the retention window
  6. per-site try/catch — one failure must not abort the run
```

**Fix these:**

| CloudPanel | Do instead |
|---|---|
| Unconfigured ⇒ exit 0, silently | Exit non-zero, or refuse to install the cron until configured |
| `&> /dev/null` + `MAILTO=""` | Log to a file; alert on failure |
| `isSuccessful()` hardcoded `true` | Propagate real exit codes; verify the artifact exists and is non-empty |
| Nothing verifies the remote copy | After upload, `rclone lsjson` the destination and compare size |
| Retention keyed on directory-name-parses-as-date | Store a manifest; prune from it |
| `chmod 760` on dump files | `640` |
| Retention `find -mtime` errors discarded | Check them |
| Global nginx/php config not backed up | Include `/etc/nginx/{nginx.conf,conf.d,global_settings}` and `/etc/php/*/fpm/pool.d/` |
| No restore tooling | Ship a `restore` command — a backup you have never restored is a hypothesis |

**Add a manifest** per run — it turns retention, verification and restore from guesswork into
lookups:

```json
{
  "run_id": "2026-09-06T04:15:00Z",
  "server": "836b167a7d073896",
  "sites": [
    {"user": "myuser", "domain": "example.com", "archive": "…/backup.tar",
     "bytes": 184320000, "sha256": "…",
     "databases": ["my-database"], "php_version": "8.3"}
  ]
}
```

**Three-tier retention** beats a single window: keep hourly for 2 days, daily for 30, monthly
for 12. CloudPanel's flat "delete anything older than N days" loses long-horizon recovery.

**Test restores on a schedule.** Automate: pull the newest archive, restore into a scratch
namespace, assert the app boots and a known row exists. Nothing else tells you your backups
work.

### 6.3 Cron management

**Keep:**

- `/etc/cron.d/<user>` files rather than user crontabs — declarative, greppable, trivially
  regenerated from the database, and removable on teardown.
- Five separate schedule columns for a clean UI.
- Rewriting the whole file from the database on every change (no partial edits to parse).

**Fix:**

| CloudPanel | Do instead |
|---|---|
| `command VARCHAR(255)` | `TEXT` — 255 is too short for real deploy commands |
| `MAILTO=""` with no logging | Default the generated command to `>> ~/logs/cron/<job>.log 2>&1` |
| Bare `php` allowed in commands | **Validate at save time**: reject bare `php`, or auto-rewrite to the site's pinned binary |
| No execution history | Wrap in a runner that records start/end/exit code to a `cron_run` table |
| Hand-made `/etc/cron.d/<user>` gets clobbered | Generate into `<user>-managed` and leave `<user>-custom` alone |

**The PHP pinning rule is worth enforcing in code.** When rendering a cron command for a PHP
site, substitute the site's actual FPM version:

```python
def render_cron_command(site, command):
    # rewrite a bare `php` / `/usr/bin/php` to the site's pinned binary
    return re.sub(r'(?<![\w/])(?:/usr/bin/)?php(?![\d.])',
                  f'/usr/bin/php{site.php_version}', command)
```

That one substitution eliminates the entire class of bug documented in §3.6.

**Add:** a "run now" button that executes the job as the site user and streams output, and a
last-run/last-exit-code column in the UI. Users cannot debug what they cannot see.

### 6.4 Cross-cutting: make failures loud

The recurring flaw across all three subsystems is **silent failure**:

- `isSuccessful()` returning a hardcoded `true` in the dump, import, tar, rclone and cleanup
  command classes
- `> /dev/null 2>&1` on the retention `find`
- `&> /dev/null` and `MAILTO=""` on generated cron
- `remote-backup:create` exiting 0 when unconfigured
- `--force` / `-f` on both mysqldump and mysql import

Any one is defensible in isolation. Together they mean a CloudPanel server can have zero
working backups, a broken nightly dump, and cron jobs failing every minute, while every
surface reports success. **This server is a live example.**

If you build your own: every scheduled operation should produce a durable record of whether
it succeeded, and the absence of a success record within the expected window should itself
raise an alert. Dead-man's-switch monitoring (healthchecks.io, Cronitor, or a simple
`last_success_at` column you alert on) is the cheapest possible fix.

---

## 7. Command Reference

### 7.1 Databases

```bash
# create a database + rw user, attached to a site
clpctl db:add --domainName=example.com --databaseName=my-database \
              --databaseUserName=john --databaseUserPassword='!secret!'

# delete (drops the DB, its users, AND its local dumps)
clpctl db:delete --databaseName=my-database --force

# export — .gz suffix triggers compression
clpctl db:export --databaseName=my-database --file=/home/myuser/backups/databases/dump.sql.gz

# import — handles .sql and .sql.gz
clpctl db:import --databaseName=my-database --file=/path/dump.sql.gz

# show root credentials for the active server
clpctl db:show:master-credentials

# dump every database of every site
clpctl db:backup --ignoreDatabases='db1,db2' --retentionPeriod=7
```

### 7.2 Backups

```bash
# remote backup run (no-op + exit 0 if not configured)
clpctl remote-backup:create
clpctl remote-backup:create --delay=true      # staggers Dropbox token refresh

# panel self-backup
sudo /home/clp/scripts/create_backup.sh

# cloud provider snapshots
clpctl aws:image:create
clpctl do:snapshot:create
clpctl gce:snapshot:create
clpctl hetzner:snapshot:create
clpctl vultr:snapshot:create
```

### 7.3 Diagnostics

```bash
# ── DATABASE ──────────────────────────────────────────────────────────────
# inventory: database → site → owning user
sqlite3 /home/clp/htdocs/app/data/db.sq3 \
  "SELECT d.name, s.domain_name, s.user
     FROM \"database\" d JOIN site s ON s.id = d.site_id ORDER BY s.user;"

# registered database servers (password column is encrypted)
sqlite3 /home/clp/htdocs/app/data/db.sq3 \
  "SELECT id, engine, version, host, port, user_name, is_active, is_default
     FROM database_server;"

# users and their permission level
sqlite3 /home/clp/htdocs/app/data/db.sq3 \
  "SELECT u.user_name, u.permissions, d.name
     FROM database_user u JOIN \"database\" d ON d.id = u.database_id;"

# on-disk size per database
mysql -e "SELECT table_schema, ROUND(SUM(data_length+index_length)/1024/1024,1) AS mb
            FROM information_schema.tables GROUP BY table_schema ORDER BY mb DESC;"

# actual grants for one user
mysql -e "SHOW GRANTS FOR 'john'@'%';"

# ── BACKUPS ───────────────────────────────────────────────────────────────
# do any dumps exist, and how fresh?
find /home/*/backups/databases -name '*.sql.gz' -printf '%TY-%Tm-%Td %10s %p\n' | sort

# dumps from the last 24h (empty output = last night's backup did not run)
find /home/*/backups/databases -name '*.sql.gz' -mtime -1 -ls

# space used by local dumps, per site
du -sh /home/*/backups/databases 2>/dev/null | sort -h

# is remote backup configured at all?
sqlite3 /home/clp/htdocs/app/data/db.sq3 \
  "SELECT \"key\", value FROM config WHERE \"key\" LIKE 'remote_backup%';"
ls -la /etc/cron.d/clp-rclone /root/.config/rclone/rclone.conf 2>/dev/null

# remote contents
sudo rclone --config /root/.config/rclone/rclone.conf lsd remote:

# panel-side failure notifications
sqlite3 /home/clp/htdocs/app/data/db.sq3 \
  "SELECT created_at, subject FROM notification ORDER BY created_at DESC LIMIT 20;"

# verify a dump is valid gzip and non-trivial
gzip -t <dump>.sql.gz && zcat <dump>.sql.gz | head -40

# ── CRON ──────────────────────────────────────────────────────────────────
# every managed cron job with its site
sqlite3 /home/clp/htdocs/app/data/db.sq3 \
  "SELECT s.user, c.minute, c.hour, c.day, c.month, c.weekday, c.command
     FROM cron_job c JOIN site s ON s.id = c.site_id ORDER BY s.user;"

# on-disk cron files
for f in /etc/cron.d/*; do echo "### $f"; cat "$f"; done

# PHP version mismatch audit (see §5.6)
grep -h -o '/usr/bin/php[0-9.]*' /etc/cron.d/* 2>/dev/null | sort | uniq -c
readlink -f /usr/bin/php

# did cron actually run a job? (site cron files log to /var/log/syslog via CRON)
grep CRON /var/log/syslog | grep <siteUser> | tail -20

# test a job by hand, as the right user
sudo -u <siteUser> /usr/bin/php8.3 /home/<siteUser>/htdocs/<domain>/artisan schedule:run

# platform schedules with no cron file live in the agent
systemctl status clp-agent
```

---

## Appendix: Path & Artifact Manifest

| Path | Purpose | Created by | Removed by |
|---|---|---|---|
| `/home/<user>/backups/databases/` | local dump root | site skeleton | `userdel -r` |
| `…/<dbName>/<Y-m-d>/<dbName>_<ts>.sql.gz` | a dump | `db:backup` | retention `find -mtime` |
| `…/<dbName>/` | per-database dump tree | `db:backup` | `db:delete` (§1.6) |
| `/home/<user>/tmp/backup.tar` | transient site archive | `remote-backup:create` | same run (`finally`) |
| `/home/<user>/site-settings.json` | restore metadata | `remote-backup:create` | same run (`finally`) |
| `/home/<user>/site-vhost` | vhost copy for restore | `remote-backup:create` | same run (`finally`) |
| `/home/clp/backups/<ts>/app/files/` | panel app copy | `create_backup.sh` | keep-3 rotation |
| `/home/clp/backups/<ts>/app/data/db.sq3` | panel DB (sqlite `.backup`) | `create_backup.sh` | keep-3 rotation |
| `/root/.config/rclone/rclone.conf` | `[remote]` definition | panel backup setup | backup disable |
| `/home/clp/.config/rclone/credentials/` | provider credentials (clp:clp, 770) | panel backup setup | `deleteCredentials()` |
| `/etc/cron.d/clp-rclone` | remote backup schedule | `Rclone::createCronJob()` | `Rclone::deleteCronJob()` |
| `/etc/cron.d/<siteUser>` | per-site cron jobs | `Updater::updateUserCrontab()` | `deleteCrontab()` on site delete |
| `/tmp/.clp_tmp_<sha1>` | transient DB password file (0400) | dump/import commands | `__destruct()` |

---

*End of document. See `cloudpanel-architecture.md` for sites, nginx, SSL, PHP-FPM and isolation.*
