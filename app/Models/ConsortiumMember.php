<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model; class ConsortiumMember extends Model { protected $fillable=['consortium_id','company_id','name','tax_id','email','phone','participation_percentage','role']; protected function casts():array{return ['participation_percentage'=>'decimal:2'];} }
