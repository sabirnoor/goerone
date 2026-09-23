<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Products extends Model
{
    use HasFactory;
    protected $table = 'products';
    protected $fillable = [
        'AgencyID',
        'UserSysId',
        'product_name',
        'product_type',
        'total_qty',
        'sold_qty',
        'buying_price',
        'selling_price',
        'is_deleted'
    ];

    protected $dates = ['created_at', 'updated_at'];
    public function images()
    {
        return $this->hasMany(Products_Images::class, 'products_id', 'id');
    }
    public function Details()
    {
        return $this->hasMany(ProductDetails::class, 'products_id', 'id');
    }
    public function PickupAddress()
    {
        return $this->hasMany(Products_PickupAddress::class, 'products_id', 'id');
    }

    public static function getProductsAPI($User, $perPage, $post = array())
    {
        $AgencyID = ($User->UserType == 1) ? $User->id : $User->AgencyID;
        $responsedata = Products::select(
            'products.id',
            'products.product_name',
            'products.total_qty',
            'products.sold_qty',
            'products.selling_price',
            'products.created_at',
            'products_images.images as primary_image',
            'product_types.name as product_types',
        )->leftJoin('product_types', 'product_types.id', '=', 'products.product_type')
            ->leftJoin('products_images', function ($join) {
                $join->on('products_images.products_id', '=', 'products.id')
                    ->where('products_images.is_primary', 1);   // LIMIT TO PRIMARY
            })
            ->where(function ($query) use ($AgencyID) {
                $query->where('products.AgencyID', $AgencyID);
            })
            ->where(function ($query) {
                $query->where('products.is_deleted', 0);
            })
            ->with(['images' => function ($q) {
                $q->select('products_id', 'images'); // only required fields
            }])
            ->with(['Details' => function ($q) {
                $q->select('products_id', 'title', 'description', 'order_by'); // only required fields
            }])
            ->with(['PickupAddress' => function ($q) {
                $q->select('products_id', 'title', 'address', 'is_primary'); // only required fields
            }])
            ->orderBy('products.id', 'DESC')
            ->paginate($perPage);

        return $responsedata;
    }
}
