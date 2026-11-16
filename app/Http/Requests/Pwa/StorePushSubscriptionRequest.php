<?php

namespace App\Http\Requests\Pwa;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Suscripción que entrega el navegador (`PushSubscription.toJSON()`): endpoint HTTPS del servicio
 * push y las claves `p256dh` y `auth` en base64url.
 */
class StorePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $base64url = 'regex:/^[A-Za-z0-9_-]+={0,2}$/';

        return [
            'endpoint' => ['required', 'string', 'max:2000', 'url:https', 'regex:/^https:\/\//'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'min:40', 'max:255', $base64url],
            'keys.auth' => ['required', 'string', 'min:16', 'max:255', $base64url],
            'content_encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
            'device_label' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'endpoint.required' => 'El navegador no entregó la dirección de la suscripción.',
            'endpoint.url' => 'La dirección de la suscripción debe ser HTTPS.',
            'endpoint.regex' => 'La dirección de la suscripción debe ser HTTPS.',
            'endpoint.max' => 'La dirección de la suscripción es demasiado larga.',
            'keys.required' => 'El navegador no entregó las claves de la suscripción.',
            'keys.*.required' => 'El navegador no entregó las claves de la suscripción.',
            'keys.*.min' => 'Las claves de la suscripción no son válidas.',
            'keys.*.max' => 'Las claves de la suscripción no son válidas.',
            'keys.*.regex' => 'Las claves de la suscripción no son válidas.',
            'content_encoding.in' => 'El cifrado de la suscripción no es compatible.',
        ];
    }
}
