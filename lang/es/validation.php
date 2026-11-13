<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Líneas de idioma de validación
    |--------------------------------------------------------------------------
    |
    | Las siguientes líneas contienen los mensajes de error predeterminados que
    | utiliza el validador. Algunas reglas tienen varias versiones, como las
    | reglas de tamaño. Puedes ajustar cada mensaje según lo necesites.
    |
    */

    'accepted' => 'Debes aceptar el campo :attribute.',
    'accepted_if' => 'Debes aceptar el campo :attribute cuando :other sea :value.',
    'active_url' => 'El campo :attribute debe ser una URL válida.',
    'after' => 'El campo :attribute debe ser una fecha posterior a :date.',
    'after_or_equal' => 'El campo :attribute debe ser una fecha posterior o igual a :date.',
    'alpha' => 'El campo :attribute solo debe contener letras.',
    'alpha_dash' => 'El campo :attribute solo debe contener letras, números, guiones y guiones bajos.',
    'alpha_num' => 'El campo :attribute solo debe contener letras y números.',
    'any_of' => 'El campo :attribute no es válido.',
    'array' => 'El campo :attribute debe ser una lista de valores.',
    'array_keys' => 'El campo :attribute solo debe contener las siguientes claves: :values.',
    'ascii' => 'El campo :attribute solo debe contener caracteres alfanuméricos y símbolos de un solo byte.',
    'base64' => 'El campo :attribute debe ser una cadena Base64 válida.',
    'before' => 'El campo :attribute debe ser una fecha anterior a :date.',
    'before_or_equal' => 'El campo :attribute debe ser una fecha anterior o igual a :date.',
    'between' => [
        'array' => 'El campo :attribute debe tener entre :min y :max elementos.',
        'file' => 'El campo :attribute debe pesar entre :min y :max kilobytes.',
        'numeric' => 'El campo :attribute debe estar entre :min y :max.',
        'string' => 'El campo :attribute debe tener entre :min y :max caracteres.',
    ],
    'boolean' => 'El campo :attribute debe ser verdadero o falso.',
    'can' => 'El campo :attribute contiene un valor no autorizado.',
    'confirmed' => 'La confirmación del campo :attribute no coincide.',
    'contains' => 'Al campo :attribute le falta un valor obligatorio.',
    'current_password' => 'La contraseña es incorrecta.',
    'date' => 'El campo :attribute debe ser una fecha válida.',
    'date_equals' => 'El campo :attribute debe ser una fecha igual a :date.',
    'date_format' => 'El campo :attribute debe coincidir con el formato :format.',
    'decimal' => 'El campo :attribute debe tener :decimal decimales.',
    'declined' => 'Debes rechazar el campo :attribute.',
    'declined_if' => 'Debes rechazar el campo :attribute cuando :other sea :value.',
    'different' => 'Los campos :attribute y :other deben ser diferentes.',
    'digits' => 'El campo :attribute debe tener :digits dígitos.',
    'digits_between' => 'El campo :attribute debe tener entre :min y :max dígitos.',
    'dimensions' => 'El campo :attribute tiene dimensiones de imagen no válidas.',
    'distinct' => 'El campo :attribute tiene un valor duplicado.',
    'doesnt_contain' => 'El campo :attribute no debe contener ninguno de los siguientes valores: :values.',
    'doesnt_end_with' => 'El campo :attribute no debe terminar con ninguno de los siguientes valores: :values.',
    'doesnt_start_with' => 'El campo :attribute no debe comenzar con ninguno de los siguientes valores: :values.',
    'email' => 'El campo :attribute debe ser una dirección de correo electrónico válida.',
    'encoding' => 'El campo :attribute debe estar codificado en :encoding.',
    'ends_with' => 'El campo :attribute debe terminar con uno de los siguientes valores: :values.',
    'enum' => 'El valor seleccionado para :attribute no es válido.',
    'exists' => 'El valor seleccionado para :attribute no es válido.',
    'extensions' => 'El campo :attribute debe tener una de las siguientes extensiones: :values.',
    'file' => 'El campo :attribute debe ser un archivo.',
    'filled' => 'El campo :attribute debe tener un valor.',
    'gt' => [
        'array' => 'El campo :attribute debe tener más de :value elementos.',
        'file' => 'El campo :attribute debe pesar más de :value kilobytes.',
        'numeric' => 'El campo :attribute debe ser mayor que :value.',
        'string' => 'El campo :attribute debe tener más de :value caracteres.',
    ],
    'gte' => [
        'array' => 'El campo :attribute debe tener :value elementos o más.',
        'file' => 'El campo :attribute debe pesar :value kilobytes o más.',
        'numeric' => 'El campo :attribute debe ser mayor o igual que :value.',
        'string' => 'El campo :attribute debe tener :value caracteres o más.',
    ],
    'hex_color' => 'El campo :attribute debe ser un color hexadecimal válido.',
    'image' => 'El campo :attribute debe ser una imagen.',
    'in' => 'El valor seleccionado para :attribute no es válido.',
    'in_array' => 'El campo :attribute debe existir en :other.',
    'in_array_keys' => 'El campo :attribute debe contener al menos una de las siguientes claves: :values.',
    'integer' => 'El campo :attribute debe ser un número entero.',
    'ip' => 'El campo :attribute debe ser una dirección IP válida.',
    'ipv4' => 'El campo :attribute debe ser una dirección IPv4 válida.',
    'ipv6' => 'El campo :attribute debe ser una dirección IPv6 válida.',
    'json' => 'El campo :attribute debe ser una cadena JSON válida.',
    'list' => 'El campo :attribute debe ser una lista.',
    'lowercase' => 'El campo :attribute debe estar en minúsculas.',
    'lt' => [
        'array' => 'El campo :attribute debe tener menos de :value elementos.',
        'file' => 'El campo :attribute debe pesar menos de :value kilobytes.',
        'numeric' => 'El campo :attribute debe ser menor que :value.',
        'string' => 'El campo :attribute debe tener menos de :value caracteres.',
    ],
    'lte' => [
        'array' => 'El campo :attribute no debe tener más de :value elementos.',
        'file' => 'El campo :attribute debe pesar :value kilobytes o menos.',
        'numeric' => 'El campo :attribute debe ser menor o igual que :value.',
        'string' => 'El campo :attribute debe tener :value caracteres o menos.',
    ],
    'mac_address' => 'El campo :attribute debe ser una dirección MAC válida.',
    'max' => [
        'array' => 'El campo :attribute no debe tener más de :max elementos.',
        'file' => 'El campo :attribute no debe pesar más de :max kilobytes.',
        'numeric' => 'El campo :attribute no debe ser mayor que :max.',
        'string' => 'El campo :attribute no debe tener más de :max caracteres.',
    ],
    'max_digits' => 'El campo :attribute no debe tener más de :max dígitos.',
    'mimes' => 'El campo :attribute debe ser un archivo de tipo: :values.',
    'mimetypes' => 'El campo :attribute debe ser un archivo de tipo: :values.',
    'min' => [
        'array' => 'El campo :attribute debe tener al menos :min elementos.',
        'file' => 'El campo :attribute debe pesar al menos :min kilobytes.',
        'numeric' => 'El campo :attribute debe ser al menos :min.',
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
    ],
    'min_digits' => 'El campo :attribute debe tener al menos :min dígitos.',
    'missing' => 'El campo :attribute no debe estar presente.',
    'missing_if' => 'El campo :attribute no debe estar presente cuando :other sea :value.',
    'missing_unless' => 'El campo :attribute no debe estar presente a menos que :other sea :value.',
    'missing_with' => 'El campo :attribute no debe estar presente cuando :values esté presente.',
    'missing_with_all' => 'El campo :attribute no debe estar presente cuando :values estén presentes.',
    'multiple_of' => 'El campo :attribute debe ser un múltiplo de :value.',
    'not_in' => 'El valor seleccionado para :attribute no es válido.',
    'not_regex' => 'El formato del campo :attribute no es válido.',
    'numeric' => 'El campo :attribute debe ser un número.',
    'password' => [
        'letters' => 'El campo :attribute debe contener al menos una letra.',
        'mixed' => 'El campo :attribute debe contener al menos una letra mayúscula y una minúscula.',
        'numbers' => 'El campo :attribute debe contener al menos un número.',
        'symbols' => 'El campo :attribute debe contener al menos un símbolo.',
        'uncompromised' => 'El valor de :attribute ha aparecido en una filtración de datos. Elige un valor diferente para :attribute.',
    ],
    'present' => 'El campo :attribute debe estar presente.',
    'present_if' => 'El campo :attribute debe estar presente cuando :other sea :value.',
    'present_unless' => 'El campo :attribute debe estar presente a menos que :other sea :value.',
    'present_with' => 'El campo :attribute debe estar presente cuando :values esté presente.',
    'present_with_all' => 'El campo :attribute debe estar presente cuando :values estén presentes.',
    'prohibited' => 'El campo :attribute no está permitido.',
    'prohibited_if' => 'El campo :attribute no está permitido cuando :other sea :value.',
    'prohibited_if_accepted' => 'El campo :attribute no está permitido cuando :other haya sido aceptado.',
    'prohibited_if_declined' => 'El campo :attribute no está permitido cuando :other haya sido rechazado.',
    'prohibited_unless' => 'El campo :attribute no está permitido a menos que :other esté en :values.',
    'prohibits' => 'El campo :attribute impide que :other esté presente.',
    'regex' => 'El formato del campo :attribute no es válido.',
    'required' => 'El campo :attribute es obligatorio.',
    'required_array_keys' => 'El campo :attribute debe contener entradas para: :values.',
    'required_if' => 'El campo :attribute es obligatorio cuando :other sea :value.',
    'required_if_accepted' => 'El campo :attribute es obligatorio cuando :other haya sido aceptado.',
    'required_if_declined' => 'El campo :attribute es obligatorio cuando :other haya sido rechazado.',
    'required_unless' => 'El campo :attribute es obligatorio a menos que :other esté en :values.',
    'required_with' => 'El campo :attribute es obligatorio cuando :values esté presente.',
    'required_with_all' => 'El campo :attribute es obligatorio cuando :values estén presentes.',
    'required_without' => 'El campo :attribute es obligatorio cuando :values no esté presente.',
    'required_without_all' => 'El campo :attribute es obligatorio cuando ninguno de :values esté presente.',
    'same' => 'El campo :attribute debe coincidir con :other.',
    'size' => [
        'array' => 'El campo :attribute debe contener :size elementos.',
        'file' => 'El campo :attribute debe pesar :size kilobytes.',
        'numeric' => 'El campo :attribute debe ser :size.',
        'string' => 'El campo :attribute debe tener :size caracteres.',
    ],
    'starts_with' => 'El campo :attribute debe comenzar con uno de los siguientes valores: :values.',
    'string' => 'El campo :attribute debe ser un texto.',
    'timezone' => 'El campo :attribute debe ser una zona horaria válida.',
    'unique' => 'El valor del campo :attribute ya está en uso.',
    'uploaded' => 'No se pudo cargar el campo :attribute.',
    'uppercase' => 'El campo :attribute debe estar en mayúsculas.',
    'url' => 'El campo :attribute debe ser una URL válida.',
    'ulid' => 'El campo :attribute debe ser un ULID válido.',
    'uuid' => 'El campo :attribute debe ser un UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Líneas de idioma de validación personalizadas
    |--------------------------------------------------------------------------
    |
    | Aquí puedes indicar mensajes de validación personalizados para atributos
    | usando la convención "atributo.regla" para nombrar las líneas. Así es
    | rápido definir un mensaje específico para una regla de un atributo.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Atributos de validación personalizados
    |--------------------------------------------------------------------------
    |
    | Las siguientes líneas sustituyen el marcador del atributo por un nombre
    | más legible, como "correo electrónico" en lugar de "email". Esto hace
    | que los mensajes sean más claros para quien usa el sistema.
    |
    */

    'attributes' => [
        // Acceso y usuarios
        'name' => 'nombre',
        'email' => 'correo electrónico',
        'password' => 'contraseña',
        'password_confirmation' => 'confirmación de la contraseña',
        'current_password' => 'contraseña actual',
        'role' => 'rol',
        'phone' => 'teléfono',
        'whatsapp' => 'WhatsApp',
        'is_root' => 'usuario raíz',
        'is_default' => 'empresa predeterminada',
        'is_active' => 'activo',

        // Relaciones
        'company_id' => 'empresa',
        'institution_id' => 'institución',
        'contact_id' => 'contacto',
        'client_id' => 'cliente',
        'tender_id' => 'licitación',
        'opportunity_id' => 'oportunidad',
        'quote_id' => 'cotización',
        'contract_id' => 'contrato',
        'document_id' => 'documento',
        'inventory_item_id' => 'producto de inventario',
        'user_id' => 'usuario',
        'owner_id' => 'responsable',
        'manager_id' => 'administrador del contrato',
        'evidence_document_id' => 'documento de evidencia',

        // Datos generales
        'title' => 'título',
        'description' => 'descripción',
        'notes' => 'notas',
        'observations' => 'observaciones',
        'status' => 'estado',
        'type' => 'tipo',
        'category' => 'categoría',
        'kind' => 'tipo de documento',
        'number' => 'número',
        'reference' => 'expediente',
        'code' => 'código',
        'tax_id' => 'RTN',
        'representative' => 'representante legal',
        'address' => 'dirección',
        'sector' => 'sector',
        'acronym' => 'siglas',
        'location' => 'ubicación',
        'position' => 'cargo',
        'department' => 'departamento',
        'contact_name' => 'nombre del contacto',
        'contact_email' => 'correo electrónico del contacto',
        'contact_phone' => 'teléfono del contacto',
        'priority' => 'prioridad',

        // Montos y cantidades
        'amount' => 'monto',
        'awarded_amount' => 'monto adjudicado',
        'estimated_amount' => 'monto estimado',
        'billed_amount' => 'monto facturado',
        'collected_amount' => 'monto cobrado',
        'retention_amount' => 'monto retenido',
        'subtotal' => 'subtotal',
        'tax' => 'impuesto',
        'tax_rate' => 'tasa de impuesto',
        'discount' => 'descuento',
        'total' => 'total',
        'quantity' => 'cantidad',
        'unit' => 'unidad',
        'unit_cost' => 'costo unitario',
        'unit_price' => 'precio unitario',
        'margin' => 'margen',
        'cost' => 'costo',
        'price' => 'precio',
        'stock' => 'existencias',
        'sku' => 'SKU',
        'brand' => 'marca',
        'currency' => 'moneda',
        'probability' => 'probabilidad',

        // Proceso comercial
        'stage' => 'etapa',
        'outcome' => 'resultado',
        'purchase_type' => 'tipo de compra',
        'opportunity_type' => 'tipo de oportunidad',
        'process_name' => 'nombre del proceso',
        'validity_days' => 'días de vigencia',
        'winner_name' => 'adjudicatario',
        'lost_reason' => 'motivo de la pérdida',
        'next_action' => 'próxima acción',
        'next_action_at' => 'fecha de la próxima acción',
        'requirements' => 'requisitos',
        'delivery_terms' => 'condiciones de entrega',
        'payment_terms' => 'condiciones de pago',
        'source_url' => 'enlace de la fuente',
        'items' => 'partidas',
        'participants' => 'participantes',
        'physical_progress' => 'avance físico',
        'financial_progress' => 'avance financiero',
        'duration_days' => 'duración en días',
        'start_trigger' => 'disparador de inicio',
        'official_in_charge' => 'funcionario a cargo',

        // Fechas
        'date' => 'fecha',
        'starts_at' => 'fecha de inicio',
        'ends_at' => 'fecha de fin',
        'start_date' => 'fecha de inicio',
        'end_date' => 'fecha de fin',
        'due_date' => 'fecha de vencimiento',
        'quoted_at' => 'fecha de cotización',
        'awarded_at' => 'fecha de adjudicación',
        'signed_at' => 'fecha de firma',
        'issued_at' => 'fecha de emisión',
        'expires_at' => 'fecha de vencimiento',
        'published_at' => 'fecha de publicación',
        'closing_date' => 'fecha de cierre',
        'expected_close_date' => 'fecha estimada de cierre',
        'clarification_deadline' => 'fecha límite de aclaraciones',
        'presentation_deadline' => 'fecha límite de presentación',
        'opening_at' => 'fecha de apertura',
        'delivered_at' => 'fecha de entrega',
        'occurred_at' => 'fecha del seguimiento',

        // Archivos
        'file' => 'archivo',
        'files' => 'archivos',
        'logo' => 'logotipo',
        'image' => 'imagen',
    ],

];
