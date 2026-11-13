<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class GeneratedDocument extends Model { protected $fillable = ['user_id','type','title','recipient','content','metadata']; protected function casts(): array { return ['metadata' => 'array']; } }
