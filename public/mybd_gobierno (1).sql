-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1:3306
-- Tiempo de generación: 22-08-2025 a las 06:36:48
-- Versión del servidor: 9.1.0
-- Versión de PHP: 8.3.14

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `mybd_gobierno`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `actividad`
--

DROP TABLE IF EXISTS `actividad`;
CREATE TABLE IF NOT EXISTS `actividad` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `subarea_id` bigint DEFAULT NULL,
  `codigo` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `actividad`
--

INSERT INTO `actividad` (`id`, `subarea_id`, `codigo`, `nombre`) VALUES
(1, NULL, 'AC1', 'Respaldos y pruebas de restauración'),
(2, NULL, 'AC2', 'Gestión de accesos y privilegios'),
(3, NULL, 'AC3', 'Gestión de parches y vulnerabilidades'),
(4, NULL, 'AC4', 'Monitoreo, logging y auditoría'),
(5, NULL, 'AC5', 'Alta disponibilidad y DRP'),
(6, NULL, 'AC6', 'Cifrado de datos en reposo y en tránsito'),
(7, NULL, 'AC7', 'Gestión de cambios y migraciones'),
(8, NULL, 'AC8', 'Clasificación y manejo de datos'),
(9, NULL, 'AC9', 'Separación de ambientes y datos de prueba'),
(10, NULL, 'AC10', 'Seguridad física y del entorno'),
(11, NULL, 'AC11', 'Relación con proveedores (DBaaS/Cloud)'),
(12, NULL, 'AC12', 'Gestión de incidentes de seguridad');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `evaluacion_cid`
--

DROP TABLE IF EXISTS `evaluacion_cid`;
CREATE TABLE IF NOT EXISTS `evaluacion_cid` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `fecha` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actividad_id` bigint NOT NULL,
  `evaluador` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `comentarios` text COLLATE utf8mb4_unicode_ci,
  `exp_c` int NOT NULL DEFAULT '0',
  `exp_i` int NOT NULL DEFAULT '0',
  `exp_d` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `evaluacion_cid`
--

INSERT INTO `evaluacion_cid` (`id`, `fecha`, `actividad_id`, `evaluador`, `comentarios`, `exp_c`, `exp_i`, `exp_d`) VALUES
(1, '2025-08-19 12:02:36', 1, 'Keylor Cortés', '', 0, 1, 0);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `evaluacion_cid_det`
--

DROP TABLE IF EXISTS `evaluacion_cid_det`;
CREATE TABLE IF NOT EXISTS `evaluacion_cid_det` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `evaluacion_id` bigint NOT NULL,
  `pregunta` varchar(400) COLLATE utf8mb4_unicode_ci NOT NULL,
  `respuesta` enum('SI','NO','NA') COLLATE utf8mb4_unicode_ci NOT NULL,
  `c_aplica` tinyint(1) NOT NULL DEFAULT '0',
  `i_aplica` tinyint(1) NOT NULL DEFAULT '0',
  `d_aplica` tinyint(1) NOT NULL DEFAULT '0',
  `requisito_id` bigint DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `evaluacion_cid_det`
--

INSERT INTO `evaluacion_cid_det` (`id`, `evaluacion_id`, `pregunta`, `respuesta`, `c_aplica`, `i_aplica`, `d_aplica`, `requisito_id`) VALUES
(1, 1, '¿Existe procedimiento para respaldar la base de datos?', 'SI', 0, 1, 0, 1),
(2, 1, '¿Cuenta con un registro/bitácora de eventos?', 'NO', 0, 1, 0, 3),
(3, 1, '¿Cuenta con políticas de control de acceso?', 'SI', 1, 0, 0, 2),
(4, 1, '¿Cuenta con seguridad en el acceso de los datos?', 'NA', 1, 0, 1, 4);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `norma`
--

DROP TABLE IF EXISTS `norma`;
CREATE TABLE IF NOT EXISTS `norma` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `familia` enum('ISO27002_2013','ISO27002_2022','COBIT4','Interna') COLLATE utf8mb4_unicode_ci NOT NULL,
  `codigo` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titulo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_norma` (`familia`,`codigo`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `norma`
--

INSERT INTO `norma` (`id`, `familia`, `codigo`, `titulo`) VALUES
(1, 'ISO27002_2013', 'A.12.3.1', 'Respaldo de la información'),
(2, 'ISO27002_2013', 'A.9.1.1', 'Política de control de acceso'),
(3, 'ISO27002_2013', 'A.12.4.1', 'Registro de eventos'),
(4, 'COBIT4', 'DS5', 'Garantizar la seguridad de los sistemas');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `requisito`
--

DROP TABLE IF EXISTS `requisito`;
CREATE TABLE IF NOT EXISTS `requisito` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `norma_id` bigint NOT NULL,
  `codigo` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_requisito` (`norma_id`,`codigo`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `requisito`
--

INSERT INTO `requisito` (`id`, `norma_id`, `codigo`, `descripcion`) VALUES
(1, 1, 'A.12.3.1', 'Respaldo de la información'),
(2, 2, 'A.9.1.1', 'Política de control de acceso'),
(3, 3, 'A.12.4.1', 'Registro de eventos'),
(4, 4, 'DS5', 'Garantizar la seguridad de los sistemas');

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `requisito`
--
ALTER TABLE `requisito`
  ADD CONSTRAINT `fk_req_norma` FOREIGN KEY (`norma_id`) REFERENCES `norma` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
