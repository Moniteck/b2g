<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recordatorio</title>
</head>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;">
        <tr>
            <td style="background:#1f2937;padding:20px 28px;color:#ffffff;">
                <h1 style="margin:0;font-size:18px;">Recordatorio de fecha critica</h1>
            </td>
        </tr>
        <tr>
            <td style="padding:28px;">
                <h2 style="margin:0 0 8px;font-size:20px;">{{ $event->title }}</h2>

                @if ($startsAt)
                    <p style="margin:0 0 16px;">
                        <strong>Fecha:</strong> {{ $startsAt }}
                    </p>
                @endif

                @if ($company)
                    <p style="margin:0 0 16px;">
                        <strong>Empresa:</strong> {{ $company }}
                    </p>
                @endif

                @if ($event->description)
                    <p style="margin:0 0 16px;">{{ $event->description }}</p>
                @endif

                <p style="margin:24px 0 0;font-size:13px;color:#6b7280;">
                    Este mensaje se genero automaticamente por el CRM B2G. Ingrese al sistema para ver el detalle completo del expediente.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
