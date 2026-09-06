<?php

namespace App\Models;

use Database\Factories\ServerHostnameReservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $server_id
 * @property int $site_id
 * @property string $hostname
 * @property Server $server
 * @property Site $site
 */
class ServerHostnameReservation extends AbstractModel
{
    /** @use HasFactory<ServerHostnameReservationFactory> */
    use HasFactory;

    protected $fillable = [
        'server_id',
        'site_id',
        'hostname',
    ];

    protected $casts = [
        'server_id' => 'integer',
        'site_id' => 'integer',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
