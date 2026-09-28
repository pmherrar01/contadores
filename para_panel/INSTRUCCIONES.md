# Panel de contadores LoRaWAN

`card_contadores_lorawan.php` es una tarjeta del panel, con la misma línea que
`card_puertas_visualizacion.php`: PHP con `$db->query()`, datos volcados a un
store de Alpine y Metronic 7 (Bootstrap 4).

## Qué muestra

- **KPIs**: contadores (con y sin nodo), comunicando / sin comunicar, posibles
  averías y avisos, consumo del periodo y de hoy, lecturas, disponibilidad,
  batería, cobertura, volumen acumulado y sin nodo. Al pulsar un KPI se filtra
  la tabla.
- **Filtro de fechas**: hoy, ayer, 7/30/90 días, este mes, mes anterior, este
  año o fechas a mano. También filtro por cliente (parámetros GET `ct_desde`,
  `ct_hasta`, `ct_cliente`).
- **Gráficas**: consumo diario de la flota con las lecturas recibidas, estado de
  conexión, top 10 de consumo, baterías, cobertura, hora a la que informan,
  consumo por cliente y un mapa de comunicaciones (contador × día).
- **Posibles averías e incidencias**: sin comunicar, válvula averiada, alarmas
  del contador, batería baja o crítica, la lectura baja (contador sustituido),
  posible fuga (nunca baja de 250 L/día en 7 días), consumo anómalo (más de 3
  veces la media), sin consumo 7 días, cobertura débil, válvula cerrada,
  prepago sin saldo, sin nodo y nodo borrado. Las de batería, fuga y consumo
  anómalo usan umbrales estimados (el vendedor no los da); se cambian arriba del
  archivo.
- **Alarmas recibidas** de los contadores en el periodo.
- **Tabla** con buscador, filtros (estado, incidencias, DN15/DN20, con/sin nodo),
  ordenación, paginación y **exportación a CSV** (se abre bien en Excel).
- **Detalle** de cada contador: datos, consumo por día, incidencias y sus
  lecturas una a una (`GET /contadores/lorawan/:devEui/lecturas`), con la trama
  en bruto.
- Gateways y recarga automática cada 5 minutos (opcional).

Los contadores se registran solos al comunicar por primera vez (endpoint de
server_panel) y aparecen como **Sin nodo** hasta que se enlazan con su nodo en
la BD (`UPDATE lorawan_contadores SET idNodo = ...`). El panel no da de alta
ni asigna nada: solo lee.

Los umbrales (26 h sin comunicar, batería 3,40/3,30 V, RSSI -115 dBm, fuga…)
están al principio del archivo, en los `define('CT_...')`.

## Integrarlo en el panel

1. Copia `card_contadores_lorawan.php` junto a las demás tarjetas.
2. Inclúyelo en la página donde quieras verlo. Necesita `$db` y `$idUser`, igual
   que el resto:
   ```php
   <?php
   // Opcional: solo los contadores de los nodos del usuario (panel de cliente)
   // $contadoresSoloUsuario = true;
   include 'card_contadores_lorawan.php';
   ?>
   ```
3. La única llamada a server_panel (lecturas una a una del detalle) va a
   `/contadores/lorawan/...` **en el mismo dominio**, como hace `live_panel`. Si
   el panel está en otro dominio, define antes de la tarjeta:
   ```html
   <script>window.CONTADORES_API = 'https://panel2.modularbox.com';</script>
   ```
4. Usa lo que ya trae Metronic: jQuery, Bootstrap 4, SweetAlert2, Alpine 3 y
   ApexCharts. Si ApexCharts no estuviera cargado, la tarjeta lo carga del CDN.

## Vista previa en este PC

```bash
cd ~/Escritorio/contadores
php -S 127.0.0.1:8090 -t para_panel/preview
```

- http://127.0.0.1:8090/ → datos reales de la BD local (conexión de solo lectura)
  y las lecturas del detalle desde server_panel local (`localhost:3002`).
- http://127.0.0.1:8090/?demo=1 → 100 contadores ficticios generados en memoria,
  con todos los tipos de incidencia.

Sin la licencia de Metronic, la vista previa usa Bootstrap 4 y una hoja de
estilos que lo imita (`preview/metronic-aproximado.css`). En el panel se verá
con los estilos de Metronic.
