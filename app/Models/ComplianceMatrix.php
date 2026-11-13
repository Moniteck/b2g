<?php
namespace App\Models; use App\Models\Concerns\BelongsToCompany; use Illuminate\Database\Eloquent\Model; class ComplianceMatrix extends Model { use BelongsToCompany; protected $fillable=['company_id','tender_id','name']; public function items(){return $this->hasMany(ComplianceItem::class);} public function tender(){return $this->belongsTo(Tender::class);} }
