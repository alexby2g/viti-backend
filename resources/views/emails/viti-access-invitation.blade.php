<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso a VITI</title>
</head>
<body style="margin:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#172033;">
    <div style="max-width:620px;margin:0 auto;padding:32px 16px;">
        <div style="background:#0b315f;border-radius:14px 14px 0 0;padding:24px;color:#fff;">
            <div style="font-size:12px;letter-spacing:1.5px;font-weight:700;opacity:.8;">AGR STUDIO</div>
            <div style="font-size:30px;font-weight:800;margin-top:4px;">VITI</div>
            <div style="font-size:14px;margin-top:4px;opacity:.9;">Visión Integral, Tecnología e Innovación</div>
        </div>

        <div style="background:#fff;padding:30px 26px;border-radius:0 0 14px 14px;box-shadow:0 8px 30px rgba(20,35,60,.08);">
            <h1 style="margin:0 0 16px;font-size:24px;">Tu acceso a VITI ha sido aprobado</h1>
            <p style="font-size:16px;line-height:1.6;">Hola {{ $cliente?->nombre ?: 'cliente' }},</p>
            <p style="font-size:16px;line-height:1.6;">Ya puedes completar tu registro y continuar con la solicitud de tu sistema.</p>

            @if($empresa)
                <p style="font-size:15px;line-height:1.6;"><strong>Empresa:</strong> {{ $empresa->nombre_comercial }}</p>
            @endif

            @if($solicitud)
                <p style="font-size:15px;line-height:1.6;"><strong>Solicitud:</strong> {{ $solicitud->codigo ?? $solicitud->titulo }}</p>
            @endif

            <div style="text-align:center;margin:30px 0;">
                <a href="{{ $url }}" style="display:inline-block;background:#6757d9;color:#fff;text-decoration:none;font-weight:700;padding:14px 24px;border-radius:9px;">Completar registro en VITI</a>
            </div>

            <p style="font-size:14px;line-height:1.6;color:#5c6678;">Este enlace es personal y temporal. @if($expiraAt) Expira el {{ $expiraAt->timezone(config('app.timezone'))->format('d/m/Y H:i') }}. @endif</p>
            <p style="font-size:13px;line-height:1.6;color:#7a8494;">Si el botón no funciona, copia y pega este enlace en tu navegador:</p>
            <p style="font-size:13px;line-height:1.6;word-break:break-all;"><a href="{{ $url }}">{{ $url }}</a></p>

            <hr style="border:0;border-top:1px solid #e7eaf0;margin:26px 0;">
            <p style="font-size:12px;color:#8a93a3;margin:0;">Este correo fue enviado automáticamente por VITI. Si no esperabas esta invitación, puedes ignorar este mensaje.</p>
        </div>
    </div>
</body>
</html>
