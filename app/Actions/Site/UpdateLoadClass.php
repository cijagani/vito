<?php

namespace App\Actions\Site;

use App\Enums\SiteLoadClass;
use App\Models\Site;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Records how busy a site is, which decides its share of the PHP-FPM pool.
 *
 * Changing this alters nothing on the server by itself; it is read the next time
 * the server is analysed.
 */
class UpdateLoadClass
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Site $site, array $input): void
    {
        Validator::make($input, [
            'load_class' => [
                'required',
                Rule::enum(SiteLoadClass::class),
            ],
        ])->validate();

        $site->load_class = SiteLoadClass::from($input['load_class']);
        $site->save();
    }
}
