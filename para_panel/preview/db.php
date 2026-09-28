<?php
// Solo para la vista previa local: imita el $db del panel ($db->query($sql)
// devuelve un array de filas asociativas) contra la BD local, en SOLO LECTURA.

class DbVistaPrevia
{
  private PDO $pdo;

  public function __construct()
  {
    // Por defecto la BD local; con CT_DB_HOST/CT_DB_NAME/CT_DB_USER/CT_DB_PASS se puede apuntar a otra (pruebas).
    $host = getenv('CT_DB_HOST') ?: '127.0.0.1';
    $nombre = getenv('CT_DB_NAME') ?: 'panel_bd_prod';
    $this->pdo = new PDO("mysql:host=$host;dbname=$nombre;charset=utf8mb4", getenv('CT_DB_USER') ?: 'root', getenv('CT_DB_PASS') ?: 'modularbox69', [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => true,
    ]);
    // Cualquier escritura falla en esta sesión.
    $this->pdo->exec('SET SESSION TRANSACTION READ ONLY');
  }

  public function query($sql)
  {
    if (!preg_match('/^\s*SELECT\b/i', $sql)) {
      throw new RuntimeException('La vista previa solo permite consultas SELECT');
    }
    return $this->pdo->query($sql)->fetchAll();
  }
}
