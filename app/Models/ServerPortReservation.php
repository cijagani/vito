<?php

namespace App\Models;

use App\Enums\PortProtocol;
use Database\Factories\ServerPortReservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $server_id
 * @property int $site_id
 * @property PortProtocol $protocol
 * @property int $port
 * @property string $purpose
 * @property Server $server
 * @property Site $site
 */
class ServerPortReservation extends AbstractModel
{
    /** @use HasFactory<ServerPortReservationFactory> */
    use HasFactory;

    protected $fillable = [
        'server_id',
        'site_id',
        'protocol',
        'port',
        'purpose',
    ];

    protected $casts = [
        'server_id' => 'integer',
        'site_id' => 'integer',
        'protocol' => PortProtocol::class,
        'port' => 'integer',
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
