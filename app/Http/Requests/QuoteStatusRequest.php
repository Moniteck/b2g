<?php
namespace App\Http\Requests;
use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;
class QuoteStatusRequest extends FormRequest { public function authorize(): bool { return true; } public function rules(): array { return ['status'=>['required','in:'.implode(',', Quote::STATUSES)]]; } }
