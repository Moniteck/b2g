<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model; class PurchaseOrder extends Model { protected $fillable=['contract_id','number','amount','issued_at','status']; protected function casts():array{return ['amount'=>'decimal:2','issued_at'=>'date'];} }
