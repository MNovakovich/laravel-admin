<?php

namespace SystemInc\LaravelAdmin;

use Illuminate\Database\Eloquent\Model;
use SystemInc\LaravelAdmin\SLA;

class GalleryImage extends Model
{
    protected $fillable = [
        'gallery_id',
        'source',
        'path_source',
        'thumb_source',
        'mobile_source',
        'order_number',
    ];

    public function getUrlAttribute()
    {
        return (new SLA)->getFile($this->source);
    }
}
