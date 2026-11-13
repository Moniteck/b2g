<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class ContactRequest extends FormRequest { public function authorize(): bool { return true; } public function rules(): array { return ['institution_id'=>['required','exists:institutions,id'],'name'=>['required','string','max:255'],'position'=>['nullable','string','max:255'],'department'=>['nullable','string','max:255'],'email'=>['nullable','email','max:255'],'phone'=>['nullable','string','max:50'],'whatsapp'=>['nullable','string','max:50'],'type'=>['nullable','string','max:100']]; } }
