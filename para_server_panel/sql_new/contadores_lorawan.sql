-- ---------------------------------------------------------------------------
-- Contadores de agua LoRaWAN
-- ---------------------------------------------------------------------------
-- ChirpStack (nuestro Network Server) manda cada trama de los contadores a
-- POST /contadores/lorawan/uplink (src/routes/contadores.ts), que las guarda
-- aquí. No tiene nada que ver con las tablas contadores_* (otro proyecto).
--
-- La primera vez que un contador comunica (al instalarlo y pulsar Sensor 5 s),
-- el endpoint lo registra en lorawan_contadores con idNodo NULL. El nodo de
-- cada contador se crea en el panel y se enlaza después en la BD:
--   UPDATE lorawan_contadores SET idNodo = <safey_nodos.id> WHERE devEui = '<devEui>';

-- Gateways LoRaWAN (las antenas que reciben a los contadores). El estado lo
-- manda el contenedor de ChirpStack cada 5 min a POST /contadores/lorawan/gateways/estado;
-- si una lectura llega por un gateway que aún no está aquí, se registra solo.
CREATE TABLE `lorawan_gateways` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `gatewayEui` char(16) NOT NULL COMMENT 'EUI del gateway en hex minúsculas (etiqueta del equipo)',
  `nombre` varchar(100) DEFAULT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `estado` varchar(10) NOT NULL DEFAULT 'nunca' COMMENT 'online / offline / nunca, según ChirpStack',
  `ultimaConexion` datetime DEFAULT NULL COMMENT 'Último contacto del gateway con ChirpStack (Europe/Madrid)',
  `rxUltimaHora` int(10) unsigned DEFAULT NULL COMMENT 'Paquetes de radio recibidos en la hora en curso',
  `txUltimaHora` int(10) unsigned DEFAULT NULL COMMENT 'Paquetes enviados (confirmaciones) en la hora en curso',
  `rx24h` int(10) unsigned DEFAULT NULL,
  `tx24h` int(10) unsigned DEFAULT NULL,
  `latitud` decimal(9,6) DEFAULT NULL,
  `longitud` decimal(9,6) DEFAULT NULL,
  `altitud` decimal(7,1) DEFAULT NULL,
  `estadoRecibidoAt` datetime DEFAULT NULL COMMENT 'Cuándo llegó el último informe de estado desde ChirpStack',
  `createdAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lorawan_gateways_gatewayEui` (`gatewayEui`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Un informe de estado cada 5 min por gateway: permite ver cuánto tiempo ha
-- estado conectado (unas 105.000 filas al año por gateway).
CREATE TABLE `lorawan_gateways_historial` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `idGateway` int(11) NOT NULL,
  `fecha` datetime NOT NULL COMMENT 'Hora del informe (Europe/Madrid)',
  `estado` varchar(10) NOT NULL,
  `ultimaConexion` datetime DEFAULT NULL,
  `rxUltimaHora` int(10) unsigned DEFAULT NULL,
  `txUltimaHora` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lorawan_gateways_historial_gateway_fecha` (`idGateway`, `fecha`),
  CONSTRAINT `fk_lorawan_gateways_historial_gateway` FOREIGN KEY (`idGateway`) REFERENCES `lorawan_gateways` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Un registro por contador. Los campos ultimo* guardan la última lectura para
-- poder listar los contadores sin recorrer las lecturas.
CREATE TABLE `lorawan_contadores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `devEui` char(16) NOT NULL COMMENT 'DevEUI LoRaWAN en hex minúsculas: identifica al contador en ChirpStack',
  `idNodo` int(11) DEFAULT NULL COMMENT 'Nodo del panel (safey_nodos.id) de este contador; NULL hasta enlazarlo',
  `idGateway` int(11) DEFAULT NULL COMMENT 'Gateway que recibió su última lectura (el que mejor lo oyó)',
  `numSerie` varchar(50) DEFAULT NULL COMMENT 'Número impreso en el contador, p. ej. 80202609010010',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `ultimaLecturaAt` datetime DEFAULT NULL COMMENT 'Hora (Europe/Madrid) de la última trama recibida',
  `ultimoVolumenM3` decimal(12,3) DEFAULT NULL,
  `ultimaBateria` decimal(6,2) DEFAULT NULL,
  `ultimoRssi` smallint(6) DEFAULT NULL,
  `ultimoSnr` decimal(5,2) DEFAULT NULL,
  `createdAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lorawan_contadores_devEui` (`devEui`),
  UNIQUE KEY `uq_lorawan_contadores_idNodo` (`idNodo`),
  KEY `idx_lorawan_contadores_idGateway` (`idGateway`),
  CONSTRAINT `fk_lorawan_contadores_safey_nodos` FOREIGN KEY (`idNodo`) REFERENCES `safey_nodos` (`id`),
  CONSTRAINT `fk_lorawan_contadores_gateway` FOREIGN KEY (`idGateway`) REFERENCES `lorawan_gateways` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Una fila por trama recibida. volumenM3/bateria/alarmas salen del codec de
-- ChirpStack (protocolo del vendedor); la trama en bruto (payloadHex) se
-- guarda siempre para poder redecodificar.
CREATE TABLE `lorawan_contadores_lecturas` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `idContador` int(11) NOT NULL,
  `deduplicationId` char(36) NOT NULL COMMENT 'UUID de ChirpStack: evita guardar dos veces la misma trama',
  `fecha` datetime NOT NULL COMMENT 'Hora de recepción (Europe/Madrid)',
  `volumenM3` decimal(12,3) DEFAULT NULL COMMENT 'Lectura acumulada del contador',
  `bateria` decimal(6,2) DEFAULT NULL COMMENT 'Voltios',
  `alarmas` varchar(255) DEFAULT NULL COMMENT 'Alarmas activas separadas por comas',
  `datos` json DEFAULT NULL COMMENT 'Objeto completo devuelto por el codec de ChirpStack',
  `payloadHex` varchar(512) NOT NULL COMMENT 'Trama en bruto (máx. 242 bytes)',
  `fCnt` int(10) unsigned DEFAULT NULL,
  `fPort` tinyint(3) unsigned DEFAULT NULL,
  `rssi` smallint(6) DEFAULT NULL COMMENT 'dBm, del gateway que mejor la oyó',
  `snr` decimal(5,2) DEFAULT NULL,
  `gatewayId` char(16) DEFAULT NULL,
  `frecuencia` int(10) unsigned DEFAULT NULL COMMENT 'Hz',
  `createdAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lorawan_lecturas_deduplicationId` (`deduplicationId`),
  KEY `idx_lorawan_lecturas_contador_fecha` (`idContador`, `fecha`),
  CONSTRAINT `fk_lorawan_lecturas_contador` FOREIGN KEY (`idContador`) REFERENCES `lorawan_contadores` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Opcional: servicio para distinguir en el panel los nodos de contadores
-- (no se duplica si el script se ejecuta dos veces).
INSERT INTO `services` (`value`)
SELECT 'Contadores' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `services` WHERE `value` = 'Contadores');
