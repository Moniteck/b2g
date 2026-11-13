<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model; class ComplianceItem extends Model { protected $fillable=['compliance_matrix_id','document_id','requirement','completed','renewal_due_at','notes']; protected function casts():array{return ['completed'=>'boolean','renewal_due_at'=>'date'];} public function document(){return $this->belongsTo(Document::class);} }
