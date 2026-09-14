<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Acceso a VITI</title>
</head>
<body style="margin:0;background:#0b0b0f;color:#f5f5f7;font-family:Arial,Helvetica,sans-serif;padding:28px 16px">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;margin:0 auto;background:#15151b;border:1px solid #2d2d39;border-radius:18px;overflow:hidden">
    <tr><td style="padding:28px 30px 12px">
        <div style="font-size:12px;letter-spacing:.14em;color:#9184ff;font-weight:700">AGR STUDIO · VITI</div>
        <h1 style="margin:10px 0 12px;font-size:28px;line-height:1.2">Tu solicitud de acceso fue aprobada</h1>
        <p style="margin:0;color:#b8b8c2;line-height:1.6">Hola {{ $cliente?->nombre ?? 'cliente' }}, VITI revisó la solicitud{{ $empresa?->nombre_comercial ? ' de '.$empresa->nombre_comercial : '' }} y habilitó tu registro seguro en VITI.</p>
    </td></tr>
    <tr><td style="padding:18px 30px">
        <table role="presentation" cellspacing="0" cellpadding="0"><tr><td style="background:#7568d8;border-radius:10px">
            <a href="{{ $url }}" style="display:inline-block;padding:14px 22px;color:#fff;text-decoration:none;font-weight:700">Crear mi cuenta VITI</a>
        </td></tr></table>
    </td></tr>
    <tr><td style="padding:0 30px 24px;color:#b8b8c2;line-height:1.6;font-size:14px">
        <p>Este enlace es personal, puede utilizarse una sola vez y vence {{ $expiraAt ? $expiraAt->timezone(config('app.timezone'))->format('d/m/Y H:i') : 'según la vigencia indicada por VITI' }}.</p>
        <p style="margin-bottom:0">Si no solicitaste este acceso, puedes ignorar este mensaje. VITI nunca te enviará una contraseña creada por terceros.</p>
    </td></tr>
    <tr><td style="padding:18px 30px;border-top:1px solid #2d2d39;color:#777786;font-size:12px">VITI · Plataforma de proyectos y soluciones digitales · Tecnología desarrollada por AGR Studio.</td></tr>
</table>
</body>
</html>
