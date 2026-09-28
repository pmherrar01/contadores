<?php
// Vista previa local de card_contadores_lorawan.php, sin el panel ni Metronic.
//   php -S 127.0.0.1:8090 -t para_panel/preview
//   http://127.0.0.1:8090/          datos reales de la BD local (solo lectura)
//   http://127.0.0.1:8090/?demo=1   100 contadores ficticios en memoria
date_default_timezone_set('Europe/Madrid');

define('URL_WEB', '/');
$idUser = 1;
$demo = isset($_GET['demo']);

if ($demo) {
  require __DIR__ . '/demo.php';
  $contadoresProveedorDatos = 'ctDemoDatos';
} else {
  require __DIR__ . '/db.php';
  $db = new DbVistaPrevia();
}
$enlaceModo = $demo ? '?' : '?demo=1';
?>
<!doctype html>
<html lang="es">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Contadores LoRaWAN · vista previa</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css">
  <link rel="stylesheet" href="metronic-aproximado.css">
  <script>
    // server_panel local (en el panel real, mismo dominio: se deja vacío).
    window.CONTADORES_API = <?= json_encode(getenv('CT_API') ?: 'http://localhost:3002') ?>;
  </script>
  <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</head>

<body>
  <div class="vp-barra d-flex align-items-center justify-content-between px-6">
    <span><b>Vista previa</b> · sin Metronic (estilos aproximados) · modo <b><?= $demo ? 'DEMO (datos ficticios)' : 'REAL (BD local, solo lectura)' ?></b></span>
    <a class="btn btn-sm btn-light" href="<?= $enlaceModo ?>"><?= $demo ? 'Ver datos reales' : 'Ver demo con 100 contadores' ?></a>
  </div>
  <div class="container-fluid py-6 px-6">
    <?php include __DIR__ . '/../card_contadores_lorawan.php'; ?>
  </div>
</body>

</html>
