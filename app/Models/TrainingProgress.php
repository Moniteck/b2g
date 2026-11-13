<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TrainingProgress extends Model { protected $fillable = ['user_id','tutorial_key','progress','score']; }
